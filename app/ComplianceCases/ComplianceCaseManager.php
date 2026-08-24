<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCasePriority;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseEvent;
use App\Models\User;
use App\Support\Enterprise;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComplianceCaseManager
{
    /** @param array<string,mixed> $data */
    public function open(User $actor, array $data): ComplianceCase
    {
        Enterprise::assertEnabled('compliance_cases');
        abort_unless($actor->can('create', ComplianceCase::class), 403);
        $data = Validator::make($data, self::openRules())->validate();

        return DB::transaction(function () use ($actor, $data): ComplianceCase {
            DB::table('compliance_case_mutexes')->where('id', 1)->lockForUpdate()->first();
            $openedAt = now();
            $next = ((int) ComplianceCase::query()->max('id')) + 1;
            $case = ComplianceCase::query()->create([
                ...Arr::only($data, ['title', 'category', 'priority', 'allegation', 'source_channel', 'source_reference', 'reporter_reference', 'confidential']),
                'number' => 'CC-'.$openedAt->format('Y').'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT),
                'status' => ComplianceCaseStatus::New, 'opened_by' => $actor->id,
                'opened_at' => $openedAt, 'governed_at' => $openedAt,
            ]);
            $this->appendEvent($case, $actor, null, $this->snapshot($case), 'opened', $data['summary'], $openedAt, 1);

            return $case->load(['opener:id,name,email', 'assignee:id,name,email', 'events.actor:id,name']);
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function record(User $actor, ComplianceCase $case, array $data): ComplianceCaseEvent
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $case, $data): ComplianceCaseEvent {
            $locked = ComplianceCase::query()->lockForUpdate()->findOrFail($case->id);
            $isManager = $actor->can('Manage Compliance Cases');
            $isInvestigator = $actor->can('Investigate Compliance Cases') && $locked->assigned_to === $actor->id;
            abort_unless($isManager || $isInvestigator, 403);
            $data = Validator::make($data, self::eventRules())->validate();
            $events = ComplianceCaseEvent::query()->where('compliance_case_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            if ($events->count() >= 200) {
                throw ValidationException::withMessages(['case' => 'A governed compliance case is limited to 200 events.']);
            }
            if ($locked->status === ComplianceCaseStatus::Closed) {
                throw ValidationException::withMessages(['case' => 'Closed compliance cases are terminal.']);
            }
            if (! $isManager && array_intersect(array_keys($data), ['assigned_to', 'due_at', 'triage_summary', 'closure_summary']) !== []) {
                abort(403, 'Only a compliance case manager may change assignment, triage, due date, or closure evidence.');
            }

            $before = $this->snapshot($locked);
            $status = isset($data['status']) ? ComplianceCaseStatus::from($data['status']) : $locked->status;
            if ($status !== $locked->status && ! in_array($status, $locked->status->allowedNext(), true)) {
                throw ValidationException::withMessages(['status' => 'The requested compliance case transition is not permitted.']);
            }
            $changes = Arr::only($data, ['status', 'assigned_to', 'due_at', 'triage_summary', 'investigation_summary', 'resolution_summary', 'closure_summary']);
            if (array_key_exists('assigned_to', $changes)) {
                $assignee = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($changes['assigned_to']);
                if (! $assignee->can('Investigate Compliance Cases')) {
                    throw ValidationException::withMessages(['assigned_to' => 'The assigned user must hold Investigate Compliance Cases.']);
                }
                $changes['assigned_to'] = $assignee->id;
            }
            $prospective = array_merge($locked->getAttributes(), $changes);
            if (! blank($prospective['assigned_to'] ?? null)) {
                $prospectiveAssignee = User::query()->whereNull('deleted_at')->whereKey($prospective['assigned_to'])->lockForUpdate()->first();
                if ($prospectiveAssignee === null || ! $prospectiveAssignee->can('Investigate Compliance Cases')) {
                    throw ValidationException::withMessages(['assigned_to' => 'The assigned investigator must remain active and authorized.']);
                }
            }
            if ($status !== ComplianceCaseStatus::Closed && array_key_exists('closure_summary', $changes)) {
                throw ValidationException::withMessages(['closure_summary' => 'Closure evidence can be recorded only by the final closer.']);
            }
            if ($status === ComplianceCaseStatus::Closed
                && array_intersect(array_keys($changes), ['assigned_to', 'due_at', 'triage_summary', 'investigation_summary', 'resolution_summary']) !== []) {
                throw ValidationException::withMessages(['status' => 'Final closure may add only the closure decision and summary.']);
            }
            $this->assertStateRequirements($status, $prospective, $actor, $locked, $events);

            if ($status === ComplianceCaseStatus::Resolved && $locked->status !== $status) {
                $changes['resolved_at'] = now();
            }
            if ($status === ComplianceCaseStatus::Closed && $locked->status !== $status) {
                $changes['closed_at'] = now();
            }
            $locked->update($changes);
            $after = $this->snapshot($locked->refresh());
            if ($before === $after) {
                throw ValidationException::withMessages(['case' => 'A compliance case event must change governed state.']);
            }
            $recordedAt = now();
            $beforeStatus = $before['status'] instanceof ComplianceCaseStatus
                ? $before['status'] : ComplianceCaseStatus::from($before['status']);
            $eventType = $status !== $beforeStatus ? Str::snake($status->value) : 'updated';

            return $this->appendEvent($locked, $actor, $before, $after, $eventType, $data['summary'], $recordedAt, $events->count() + 1)
                ->load('actor:id,name');
        }, 3);
    }

    /** @return array<string,mixed> */
    public static function openRules(): array
    {
        return [
            'title' => 'required|string|max:255', 'category' => ['required', Rule::enum(ComplianceCaseCategory::class)],
            'priority' => ['required', Rule::enum(ComplianceCasePriority::class)], 'allegation' => 'required|string|max:30000',
            'source_channel' => 'nullable|string|max:100', 'source_reference' => 'nullable|string|max:2000',
            'reporter_reference' => 'nullable|string|max:255', 'confidential' => 'sometimes|boolean',
            'summary' => 'required|string|max:30000',
            'number' => 'prohibited', 'status' => 'prohibited', 'opened_by' => 'prohibited', 'assigned_to' => 'prohibited',
            'opened_at' => 'prohibited', 'resolved_at' => 'prohibited', 'closed_at' => 'prohibited', 'governed_at' => 'prohibited',
        ];
    }

    /** @return array<string,mixed> */
    public static function eventRules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(ComplianceCaseStatus::class)],
            'assigned_to' => 'sometimes|required|integer|exists:users,id', 'due_at' => 'sometimes|nullable|date',
            'triage_summary' => 'sometimes|nullable|string|max:30000', 'investigation_summary' => 'sometimes|nullable|string|max:30000',
            'resolution_summary' => 'sometimes|nullable|string|max:30000', 'closure_summary' => 'sometimes|nullable|string|max:30000',
            'summary' => 'required|string|max:30000',
            'version' => 'prohibited', 'event_type' => 'prohibited', 'before_snapshot' => 'prohibited',
            'after_snapshot' => 'prohibited', 'recorded_by' => 'prohibited', 'recorded_at' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }

    /** @param array<string,mixed> $prospective */
    private function assertStateRequirements(ComplianceCaseStatus $status, array $prospective, User $actor, ComplianceCase $case, $events): void
    {
        if ($status === ComplianceCaseStatus::Triaged && (blank($prospective['assigned_to'] ?? null) || blank($prospective['triage_summary'] ?? null))) {
            throw ValidationException::withMessages(['status' => 'Triage requires an active investigator and triage summary.']);
        }
        if (in_array($status, [ComplianceCaseStatus::Investigating, ComplianceCaseStatus::ActionRequired, ComplianceCaseStatus::Resolved, ComplianceCaseStatus::Closed], true)
            && blank($prospective['assigned_to'] ?? null)) {
            throw ValidationException::withMessages(['status' => 'Investigation requires an assigned investigator.']);
        }
        if (in_array($status, [ComplianceCaseStatus::ActionRequired, ComplianceCaseStatus::Resolved, ComplianceCaseStatus::Closed], true)
            && blank($prospective['investigation_summary'] ?? null)) {
            throw ValidationException::withMessages(['status' => 'This state requires an investigation summary.']);
        }
        if (in_array($status, [ComplianceCaseStatus::Resolved, ComplianceCaseStatus::Closed], true)
            && blank($prospective['resolution_summary'] ?? null)) {
            throw ValidationException::withMessages(['status' => 'Resolution requires a resolution summary.']);
        }
        if ($status === ComplianceCaseStatus::Closed) {
            abort_unless($actor->can('Manage Compliance Cases'), 403);
            if (blank($prospective['closure_summary'] ?? null)) {
                throw ValidationException::withMessages(['status' => 'Closure requires an independent closure summary.']);
            }
            $investigatorIds = $events->pluck('after_snapshot')->map(fn ($snapshot) => data_get($snapshot, 'assigned_to.id'))->filter()->push($case->assigned_to)->unique();
            $decisionActors = $events->filter(function (ComplianceCaseEvent $event): bool {
                return data_get($event->before_snapshot, 'investigation_summary') !== data_get($event->after_snapshot, 'investigation_summary')
                    || data_get($event->before_snapshot, 'resolution_summary') !== data_get($event->after_snapshot, 'resolution_summary');
            })->pluck('recorded_by');
            abort_if($actor->id === $case->opened_by || $investigatorIds->contains($actor->id) || $decisionActors->contains($actor->id), 403,
                'The opener and every investigation or resolution actor are excluded from final closure.');
        }
    }

    /** @return array<string,mixed> */
    private function snapshot(ComplianceCase $case): array
    {
        $case->load(['opener:id,name,email', 'assignee:id,name,email']);

        return $case->only([
            'id', 'number', 'title', 'category', 'priority', 'status', 'allegation', 'source_channel', 'source_reference',
            'reporter_reference', 'confidential', 'due_at', 'triage_summary', 'investigation_summary', 'resolution_summary',
            'closure_summary', 'opened_at', 'resolved_at', 'closed_at', 'governed_at',
        ]) + [
            'opened_by' => $case->opener?->only(['id', 'name', 'email']),
            'assigned_to' => $case->assignee?->only(['id', 'name', 'email']),
        ];
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed> $after */
    private function appendEvent(ComplianceCase $case, User $actor, ?array $before, array $after, string $type, string $summary, $recordedAt, int $version): ComplianceCaseEvent
    {
        $payload = [
            'compliance_case_id' => $case->id, 'version' => $version, 'event_type' => $type,
            'before_snapshot' => $before, 'after_snapshot' => $after, 'summary' => $summary,
            'recorded_by' => $actor->id, 'recorded_at' => $recordedAt->toIso8601String(),
        ];

        return $case->events()->create($payload + [
            'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ]);
    }
}
