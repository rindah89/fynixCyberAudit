<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseCommunicationDecisionType;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseCommunicationDecision;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Enterprise;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComplianceCaseCommunicationManager
{
    /** @param array{audience:string,purpose:string,decision:string,deadline_at?:?string,external_reference?:?string} $data */
    public function record(User $actor, ComplianceCase $case, array $data): ComplianceCaseCommunicationDecision
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $case, $data): ComplianceCaseCommunicationDecision {
            $locked = ComplianceCase::query()->lockForUpdate()->findOrFail($case->id);
            abort_unless($actor->can('Manage Compliance Cases') && $actor->can('view', $locked), 403);
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $locked);
            $data = Validator::make($data, self::rules())->validate();
            $decision = ComplianceCaseCommunicationDecisionType::from($data['decision']);
            if ($decision === ComplianceCaseCommunicationDecisionType::Sent && blank($data['external_reference'] ?? null)) {
                throw ValidationException::withMessages(['external_reference' => 'A sent decision requires an unverified external reference.']);
            }
            $existing = ComplianceCaseCommunicationDecision::query()->where('compliance_case_id', $locked->id)->orderBy('version')->lockForUpdate()->get();
            if ($existing->count() >= 100) {
                throw ValidationException::withMessages(['case' => 'A governed compliance case is limited to 100 communication decisions.']);
            }
            $decidedAt = now()->startOfSecond();
            $record = new ComplianceCaseCommunicationDecision([
                'compliance_case_id' => $locked->id, 'version' => $existing->count() + 1,
                'audience' => trim($data['audience']), 'purpose' => trim($data['purpose']),
                'decision' => $decision,
                'deadline_at' => isset($data['deadline_at']) ? Carbon::parse($data['deadline_at'])->utc()->startOfSecond() : null,
                'external_reference' => isset($data['external_reference']) ? trim($data['external_reference']) : null,
                'decided_by' => $actor->id, 'decider_snapshot' => $actor->only(['id', 'name', 'email']),
                'case_snapshot' => ['id' => $locked->id, 'number' => $locked->number, 'status' => $locked->status->value],
                'decided_at' => $decidedAt,
            ]);
            $record->fingerprint = hash('sha256', CanonicalJson::encode($this->payload($record)));
            $record->save();

            return $record;
        }, 3);
    }

    public function history(User $actor, ComplianceCase $case, int $perPage): LengthAwarePaginator
    {
        Enterprise::assertEnabled('compliance_cases');
        abort_unless($actor->can('view', $case), 403);

        return $case->communicationDecisions()->paginate($perPage);
    }

    /** @return array<string,mixed> */
    public function payload(ComplianceCaseCommunicationDecision $record): array
    {
        return [
            'compliance_case_id' => $record->compliance_case_id, 'version' => $record->version,
            'audience' => $record->audience, 'purpose' => $record->purpose,
            'decision' => $record->decision instanceof \BackedEnum ? $record->decision->value : $record->decision,
            'deadline_at' => $record->deadline_at?->toIso8601String(),
            'external_reference' => $record->external_reference, 'decided_by' => $record->decided_by,
            'decider_snapshot' => $record->decider_snapshot, 'case_snapshot' => $record->case_snapshot,
            'decided_at' => $record->decided_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public static function rules(): array
    {
        return [
            'audience' => 'required|string|max:40', 'purpose' => 'required|string|max:30000',
            'decision' => ['required', Rule::enum(ComplianceCaseCommunicationDecisionType::class)],
            'deadline_at' => 'nullable|date', 'external_reference' => 'nullable|string|max:255',
            'id' => 'prohibited', 'version' => 'prohibited', 'fingerprint' => 'prohibited',
            'decided_by' => 'prohibited', 'decided_at' => 'prohibited', 'decider_snapshot' => 'prohibited',
            'case_snapshot' => 'prohibited',
        ];
    }
}
