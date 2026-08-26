<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseConflictDecision;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseConflictDecision as ConflictDecision;
use App\Models\ComplianceCaseConflictDeclaration;
use App\Models\ComplianceCaseEvent;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Enterprise;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComplianceCaseConflictManager
{
    /** @param array{subject_user_id:int,nature:string,rationale:string} $data */
    public function declare(User $actor, ComplianceCase $case, array $data): ComplianceCaseConflictDeclaration
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $case, $data): ComplianceCaseConflictDeclaration {
            $locked = ComplianceCase::query()->lockForUpdate()->findOrFail($case->id);
            abort_unless($actor->can('update', $locked), 403);
            $data = Validator::make($data, self::declarationRules())->validate();
            $data['nature'] = trim($data['nature']);
            $data['rationale'] = trim($data['rationale']);
            if ($data['nature'] === '' || $data['rationale'] === '') {
                throw ValidationException::withMessages(['nature' => 'A conflict nature and rationale are required.']);
            }
            $subject = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($data['subject_user_id']);
            $event = ComplianceCaseEvent::query()->where('compliance_case_id', $locked->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            $existing = ComplianceCaseConflictDeclaration::query()->where('compliance_case_id', $locked->id)->orderBy('version')->lockForUpdate()->get();
            if ($existing->count() >= 20) {
                throw ValidationException::withMessages(['case' => 'A governed compliance case is limited to 20 conflict declarations.']);
            }
            $declaredAt = now()->startOfSecond();
            $declaration = new ComplianceCaseConflictDeclaration([
                'compliance_case_id' => $locked->id, 'compliance_case_event_id' => $event->id,
                'version' => $existing->count() + 1, 'subject_user_id' => $subject->id,
                'subject_snapshot' => $subject->only(['id', 'name', 'email']),
                'declared_by' => $actor->id, 'declarer_snapshot' => $actor->only(['id', 'name', 'email']),
                'nature' => $data['nature'], 'rationale' => $data['rationale'],
                'case_snapshot' => app(ComplianceCaseInvestigationPlanManager::class)->caseSnapshot($locked, $event),
                'latest_event_snapshot' => $event->only(['id', 'version', 'event_type', 'fingerprint']) + [
                    'recorded_at' => $event->recorded_at->toIso8601String(),
                ],
                'declared_at' => $declaredAt,
            ]);
            $declaration->fingerprint = hash('sha256', CanonicalJson::encode($this->declarationPayload($declaration)));
            $declaration->save();

            return $declaration->load(['subject:id,name,email', 'declarer:id,name,email', 'decision']);
        }, 3);
    }

    /** @param array{decision:string,summary:string} $data */
    public function decide(User $actor, ComplianceCaseConflictDeclaration $declaration, array $data): ConflictDecision
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $declaration, $data): ConflictDecision {
            $case = ComplianceCase::query()->lockForUpdate()->findOrFail($declaration->compliance_case_id);
            $locked = ComplianceCaseConflictDeclaration::query()->where('compliance_case_id', $case->id)->lockForUpdate()->findOrFail($declaration->id);
            abort_unless($actor->can('Manage Compliance Cases') && $actor->can('view', $case), 403);
            abort_if($actor->id === $locked->subject_user_id || $actor->id === $locked->declared_by, 403,
                'The subject and declarer cannot decide the conflict.');
            $this->assertClear($actor, $case);
            $data = Validator::make($data, self::decisionRules())->validate();
            $data['summary'] = trim($data['summary']);
            if ($data['summary'] === '') {
                throw ValidationException::withMessages(['summary' => 'A conflict decision summary is required.']);
            }
            if (ConflictDecision::query()->where('compliance_case_conflict_declaration_id', $locked->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['declaration' => 'This conflict declaration already has a terminal decision.']);
            }
            $actor = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($actor->id);
            $decidedAt = now()->startOfSecond();
            $decision = new ConflictDecision([
                'compliance_case_conflict_declaration_id' => $locked->id,
                'decision' => ComplianceCaseConflictDecision::from($data['decision']),
                'summary' => $data['summary'], 'decided_by' => $actor->id,
                'decider_snapshot' => $actor->only(['id', 'name', 'email']),
                'declaration_snapshot' => ['id' => $locked->id, 'fingerprint' => $locked->fingerprint] + $this->declarationPayload($locked),
                'decided_at' => $decidedAt,
            ]);
            $decision->fingerprint = hash('sha256', CanonicalJson::encode($this->decisionPayload($decision)));
            $decision->save();

            return $decision->load('decider:id,name,email');
        }, 3);
    }

    public function history(User $actor, ComplianceCase $case, int $perPage): LengthAwarePaginator
    {
        Enterprise::assertEnabled('compliance_cases');
        abort_unless($actor->can('view', $case), 403);

        return $case->conflictDeclarations()->with(['subject:id,name,email', 'declarer:id,name,email', 'decision.decider:id,name,email'])->paginate($perPage);
    }

    public function assertClear(User $actor, ComplianceCase $case): void
    {
        abort_if($this->isRecused($actor->id, $case->id), 403, 'A confirmed conflict recuses this actor from governed case work.');
    }

    public function assertAssignable(?int $userId, int $caseId): void
    {
        if ($userId === null) {
            return;
        }
        abort_if($this->isRecused($userId, $caseId), 403, 'A confirmed conflict recuses this actor from governed case work.');
    }

    public function isRecused(int $userId, int $caseId): bool
    {
        return ComplianceCaseConflictDeclaration::query()->where('compliance_case_id', $caseId)
            ->where('subject_user_id', $userId)
            ->whereHas('decision', fn ($query) => $query->where('decision', ComplianceCaseConflictDecision::Confirmed->value))
            ->exists();
    }

    /** @return array<string,mixed> */
    public function declarationPayload(ComplianceCaseConflictDeclaration $declaration): array
    {
        return [
            'compliance_case_id' => $declaration->compliance_case_id,
            'compliance_case_event_id' => $declaration->compliance_case_event_id,
            'version' => $declaration->version, 'subject_user_id' => $declaration->subject_user_id,
            'subject_snapshot' => $declaration->subject_snapshot, 'declared_by' => $declaration->declared_by,
            'declarer_snapshot' => $declaration->declarer_snapshot, 'nature' => $declaration->nature,
            'rationale' => $declaration->rationale, 'case_snapshot' => $declaration->case_snapshot,
            'latest_event_snapshot' => $declaration->latest_event_snapshot,
            'declared_at' => $declaration->declared_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function decisionPayload(ConflictDecision $decision): array
    {
        return [
            'compliance_case_conflict_declaration_id' => $decision->compliance_case_conflict_declaration_id,
            'decision' => $decision->decision instanceof \BackedEnum ? $decision->decision->value : $decision->decision,
            'summary' => $decision->summary, 'decided_by' => $decision->decided_by,
            'decider_snapshot' => $decision->decider_snapshot, 'declaration_snapshot' => $decision->declaration_snapshot,
            'decided_at' => $decision->decided_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public static function declarationRules(): array
    {
        return [
            'subject_user_id' => 'required|integer|exists:users,id',
            'nature' => 'required|string|max:30000', 'rationale' => 'required|string|max:30000',
            'id' => 'prohibited', 'version' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }

    /** @return array<string,mixed> */
    public static function decisionRules(): array
    {
        return [
            'decision' => ['required', Rule::enum(ComplianceCaseConflictDecision::class)],
            'summary' => 'required|string|max:30000',
            'id' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }
}
