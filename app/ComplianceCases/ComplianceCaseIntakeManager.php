<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCaseIntakeDecision;
use App\Enums\ComplianceCasePriority;
use App\Models\ComplianceCaseIntake;
use App\Models\ComplianceCaseIntakeDisposition;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Enterprise;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComplianceCaseIntakeManager
{
    /** @param array<string,mixed> $data */
    public function submit(User $actor, array $data): ComplianceCaseIntake
    {
        Enterprise::assertEnabled('compliance_cases');
        abort_if($actor->trashed(), 403);
        foreach (['title', 'allegation', 'source_channel', 'source_reference', 'reporter_message'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }
        $data['confidential'] ??= true;
        $data = Validator::make($data, self::submissionRules())->validate();

        return DB::transaction(function () use ($actor, $data): ComplianceCaseIntake {
            DB::table('compliance_case_intake_mutexes')->where('id', 1)->lockForUpdate()->first();
            $lockedActor = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($actor->id);
            $submittedAt = now()->startOfSecond();
            $next = ((int) ComplianceCaseIntake::query()->max('id')) + 1;
            $attributes = [
                ...Arr::only($data, ['title', 'category', 'priority', 'allegation', 'source_channel', 'source_reference', 'confidential', 'reporter_message']),
                'reference' => 'CCI-'.$submittedAt->format('Y').'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT),
                'submitted_by' => $lockedActor->id,
                'reporter_snapshot' => $lockedActor->only(['id', 'name', 'email']),
                'submitted_at' => $submittedAt,
            ];
            $intake = new ComplianceCaseIntake($attributes);
            $intake->fingerprint = hash('sha256', CanonicalJson::encode($this->submissionPayload($intake)));
            $intake->save();

            return $intake->load('reporter:id,name,email');
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function decide(User $actor, ComplianceCaseIntake $intake, array $data): ComplianceCaseIntakeDisposition
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $intake, $data): ComplianceCaseIntakeDisposition {
            $locked = ComplianceCaseIntake::query()->lockForUpdate()->findOrFail($intake->id);
            abort_unless($actor->can('Manage Compliance Cases'), 403);
            abort_if($actor->id === $locked->submitted_by, 403, 'The intake reporter cannot decide their own submission.');
            if (isset($data['summary']) && is_string($data['summary'])) {
                $data['summary'] = trim($data['summary']);
            }
            $data = Validator::make($data, self::decisionRules())->validate();
            if (ComplianceCaseIntakeDisposition::query()->where('compliance_case_intake_id', $locked->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['intake' => 'This compliance case intake already has a terminal disposition.']);
            }
            $lockedActor = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($actor->id);
            $decision = ComplianceCaseIntakeDecision::from($data['decision']);
            $case = null;
            if ($decision === ComplianceCaseIntakeDecision::Accepted) {
                $case = app(ComplianceCaseManager::class)->open($lockedActor, [
                    'title' => $locked->title, 'category' => $locked->category->value, 'priority' => $locked->priority->value,
                    'allegation' => $locked->allegation, 'source_channel' => 'Compliance case intake',
                    'source_reference' => $locked->reference, 'reporter_reference' => (string) $locked->submitted_by,
                    'confidential' => $locked->confidential,
                    'summary' => 'Accepted governed intake '.$locked->reference.': '.$data['summary'],
                ])->load('events.actor:id,name,email');
            }
            $decidedAt = now()->startOfSecond();
            $attributes = [
                'compliance_case_intake_id' => $locked->id, 'compliance_case_id' => $case?->id,
                'decision' => $decision, 'summary' => trim($data['summary']), 'decided_by' => $lockedActor->id,
                'actor_snapshot' => $lockedActor->only(['id', 'name', 'email']),
                'intake_snapshot' => ['id' => $locked->id] + $this->submissionPayload($locked) + ['fingerprint' => $locked->fingerprint],
                'case_snapshot' => $case === null ? null : [
                    'case' => $case->only(['id', 'number', 'status', 'opened_at', 'governed_at']),
                    'opening_event' => $case->events->first()?->attributesToArray(),
                ],
                'decided_at' => $decidedAt,
            ];
            $disposition = new ComplianceCaseIntakeDisposition($attributes);
            $disposition->fingerprint = hash('sha256', CanonicalJson::encode($this->decisionPayload($disposition)));
            $disposition->save();

            return $disposition->load(['actor:id,name,email', 'complianceCase']);
        }, 3);
    }

    public function managerHistory(int $perPage): LengthAwarePaginator
    {
        return ComplianceCaseIntake::query()->with(['reporter:id,name,email', 'decision.actor:id,name,email', 'decision.complianceCase'])
            ->latest('id')->paginate($perPage);
    }

    public function reporterHistory(User $actor, int $perPage): LengthAwarePaginator
    {
        $history = ComplianceCaseIntake::query()->where('submitted_by', $actor->id)->with('decision:id,compliance_case_intake_id,decision,summary,decided_at,fingerprint')
            ->latest('id')->paginate($perPage);
        $history->setCollection($history->getCollection()->map(fn (ComplianceCaseIntake $intake): array => $this->reporterProjection($intake)));

        return $history;
    }

    /** @return array<string,mixed> */
    public function reporterProjection(ComplianceCaseIntake $intake): array
    {
        return [
            'reference' => $intake->reference, 'title' => $intake->title,
            'category' => $intake->category, 'priority' => $intake->priority, 'source_channel' => $intake->source_channel,
            'submitted_at' => $intake->submitted_at, 'fingerprint' => $intake->fingerprint,
            'decision' => $intake->decision?->only(['decision', 'summary', 'decided_at', 'fingerprint']),
        ];
    }

    /** @return array<string,mixed> */
    public function submissionPayload(ComplianceCaseIntake $intake): array
    {
        return [
            'reference' => $intake->reference, 'title' => $intake->title,
            'category' => $intake->category instanceof \BackedEnum ? $intake->category->value : $intake->category,
            'priority' => $intake->priority instanceof \BackedEnum ? $intake->priority->value : $intake->priority,
            'allegation' => $intake->allegation, 'source_channel' => $intake->source_channel,
            'source_reference' => $intake->source_reference, 'confidential' => (bool) $intake->confidential,
            'reporter_message' => $intake->reporter_message, 'submitted_by' => $intake->submitted_by,
            'reporter_snapshot' => $intake->reporter_snapshot,
            'submitted_at' => $intake->submitted_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function decisionPayload(ComplianceCaseIntakeDisposition $decision): array
    {
        return [
            'compliance_case_intake_id' => $decision->compliance_case_intake_id,
            'compliance_case_id' => $decision->compliance_case_id,
            'decision' => $decision->decision instanceof \BackedEnum ? $decision->decision->value : $decision->decision,
            'summary' => $decision->summary, 'decided_by' => $decision->decided_by,
            'actor_snapshot' => $decision->actor_snapshot, 'intake_snapshot' => $decision->intake_snapshot,
            'case_snapshot' => $decision->case_snapshot, 'decided_at' => $decision->decided_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public static function submissionRules(): array
    {
        return [
            'title' => 'required|string|max:255', 'category' => ['required', Rule::enum(ComplianceCaseCategory::class)],
            'priority' => ['required', Rule::enum(ComplianceCasePriority::class)], 'allegation' => 'required|string|max:30000',
            'source_channel' => 'required|string|max:100', 'source_reference' => 'nullable|string|max:2000',
            'confidential' => 'sometimes|boolean', 'reporter_message' => 'nullable|string|max:30000',
            'id' => 'prohibited', 'reference' => 'prohibited', 'submitted_by' => 'prohibited', 'reporter_snapshot' => 'prohibited',
            'submitted_at' => 'prohibited', 'fingerprint' => 'prohibited', 'decision' => 'prohibited',
            'created_at' => 'prohibited', 'updated_at' => 'prohibited',
        ];
    }

    /** @return array<string,mixed> */
    public static function decisionRules(): array
    {
        return [
            'decision' => ['required', Rule::enum(ComplianceCaseIntakeDecision::class)], 'summary' => 'required|string|max:30000',
            'id' => 'prohibited', 'compliance_case_intake_id' => 'prohibited', 'compliance_case_id' => 'prohibited', 'decided_by' => 'prohibited', 'actor_snapshot' => 'prohibited',
            'intake_snapshot' => 'prohibited', 'case_snapshot' => 'prohibited', 'decided_at' => 'prohibited', 'fingerprint' => 'prohibited',
            'created_at' => 'prohibited', 'updated_at' => 'prohibited',
        ];
    }
}
