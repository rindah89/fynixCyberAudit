<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCaseInterviewStatus;
use App\Enums\ComplianceCaseInvestigationPlanDecision;
use App\Enums\ComplianceCasePriority;
use App\Enums\ComplianceCaseStatus;
use App\Enums\GovernanceIssueStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseActionIssue;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseInterview;
use App\Models\ComplianceCaseInvestigationPlan;
use App\Models\ComplianceCaseInvestigationPlanReview;
use App\Models\ComplianceCaseLegalHold;
use App\Models\ComplianceCaseLegalHoldRelease;
use App\Models\GovernanceIssueLifecycle;
use App\Models\User;
use App\Services\GovernanceIssueLifecycleManager;
use App\Support\CanonicalJson;
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
                'opened_at' => $openedAt, 'governed_at' => $openedAt, 'investigation_planning_governed_at' => $openedAt,
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
            $interviews = ComplianceCaseInterview::query()->where('compliance_case_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $legalHolds = ComplianceCaseLegalHold::query()->where('compliance_case_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $legalHoldReleases = ComplianceCaseLegalHoldRelease::query()->whereIn('compliance_case_legal_hold_id', $legalHolds->pluck('id'))
                ->orderBy('compliance_case_legal_hold_id')->lockForUpdate()->get()->keyBy('compliance_case_legal_hold_id');
            $issues = ComplianceCaseActionIssue::query()->where('compliance_case_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $plans = ComplianceCaseInvestigationPlan::query()->where('compliance_case_id', $locked->id)->orderBy('version')->lockForUpdate()->get();
            $planReviews = ComplianceCaseInvestigationPlanReview::query()->whereIn('compliance_case_investigation_plan_id', $plans->pluck('id'))->orderBy('compliance_case_investigation_plan_id')->lockForUpdate()->get()->keyBy('compliance_case_investigation_plan_id');
            $plans->each(fn (ComplianceCaseInvestigationPlan $plan) => $plan->setRelation('review', $planReviews->get($plan->id)));
            if ($issues->isNotEmpty()) {
                $lifecycles = GovernanceIssueLifecycle::query()->where('issue_type', ComplianceCaseActionIssue::class)
                    ->whereIn('issue_id', $issues->pluck('id'))->orderBy('issue_id')->lockForUpdate()->get()->keyBy('issue_id');
                $issues->each(fn (ComplianceCaseActionIssue $issue) => $issue->setRelation('lifecycle', $lifecycles->get($issue->id)));
            }
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
            if ($locked->investigation_planning_governed_at !== null && $locked->status === ComplianceCaseStatus::Triaged && $status === ComplianceCaseStatus::Investigating) {
                $latestPlan = $plans->last();
                $latestEvent = $events->last();
                if ($latestPlan === null || $latestPlan->review?->decision !== ComplianceCaseInvestigationPlanDecision::Approved
                    || data_get($latestPlan->case_snapshot, 'event.fingerprint') !== $latestEvent?->fingerprint
                    || $latestPlan->target_completion_at->endOfDay()->isPast()) {
                    throw ValidationException::withMessages(['investigation_plan' => 'Investigation requires an independently approved plan bound to the current triaged case context.']);
                }
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
            if ($status === ComplianceCaseStatus::Closed
                && $legalHolds->contains(fn (ComplianceCaseLegalHold $hold): bool => ! $legalHoldReleases->has($hold->id))) {
                throw ValidationException::withMessages(['status' => 'Every legal hold must be independently released before case closure.']);
            }
            $this->assertStateRequirements($status, $prospective, $actor, $locked, $events, $issues);
            if (in_array($status, [ComplianceCaseStatus::Resolved, ComplianceCaseStatus::Closed], true)
                && $interviews->contains(fn (ComplianceCaseInterview $interview): bool => $interview->status === ComplianceCaseInterviewStatus::Scheduled)) {
                throw ValidationException::withMessages(['status' => 'Every scheduled interview must be conducted or cancelled before resolution.']);
            }

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

            $event = $this->appendEvent($locked, $actor, $before, $after, $eventType, $data['summary'], $recordedAt, $events->count() + 1);
            if ($status === ComplianceCaseStatus::ActionRequired && $beforeStatus !== $status) {
                $this->openActionIssue($locked, $event, $actor);
            }

            return $event->load('actor:id,name');
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
            'investigation_planning_governed_at' => 'prohibited',
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
            'investigation_planning_governed_at' => 'prohibited',
        ];
    }

    /** @param array<string,mixed> $prospective */
    private function assertStateRequirements(ComplianceCaseStatus $status, array $prospective, User $actor, ComplianceCase $case, $events, $issues): void
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
        if ($case->status === ComplianceCaseStatus::ActionRequired && $status !== ComplianceCaseStatus::ActionRequired
            && $issues->contains(fn (ComplianceCaseActionIssue $issue): bool => $issue->lifecycle?->status !== GovernanceIssueStatus::Closed)) {
            throw ValidationException::withMessages(['status' => 'Every action-required issue must be independently verified and closed before the case can leave Action Required.']);
        }
    }

    private function openActionIssue(ComplianceCase $case, ComplianceCaseEvent $event, User $actor): ComplianceCaseActionIssue
    {
        $sourceSnapshot = [
            'case' => $event->after_snapshot,
            'event' => [
                'id' => $event->id, 'compliance_case_id' => $event->compliance_case_id, 'version' => $event->version,
                'event_type' => $event->event_type, 'before_snapshot' => $event->before_snapshot,
                'after_snapshot' => $event->after_snapshot, 'summary' => $event->summary,
                'recorded_by' => $event->recorded_by, 'recorded_at' => $event->recorded_at->toIso8601String(),
                'fingerprint' => $event->fingerprint,
            ],
        ];
        $openedAt = now();
        $payload = [
            'compliance_case_id' => $case->id, 'compliance_case_event_id' => $event->id,
            'owner_id' => $case->assigned_to, 'opened_by' => $actor->id,
            'title' => "{$case->number}: action required", 'description' => $case->investigation_summary ?: $event->summary,
            'severity' => strtolower($case->priority->value),
            'source_snapshot' => $sourceSnapshot, 'opened_at' => $openedAt->toIso8601String(),
        ];
        $issue = ComplianceCaseActionIssue::query()->create($payload + [
            'status' => GovernanceIssueStatus::Open->value,
            'fingerprint' => hash('sha256', CanonicalJson::encode($payload)),
        ]);
        app(GovernanceIssueLifecycleManager::class)->register($issue, $actor);

        return $issue;
    }

    /** @return array<string,mixed> */
    private function snapshot(ComplianceCase $case): array
    {
        $case->load(['opener:id,name,email', 'assignee:id,name,email']);

        return $case->only([
            'id', 'number', 'title', 'category', 'priority', 'status', 'allegation', 'source_channel', 'source_reference',
            'reporter_reference', 'confidential', 'due_at', 'triage_summary', 'investigation_summary', 'resolution_summary',
            'closure_summary', 'opened_at', 'resolved_at', 'closed_at', 'governed_at', 'investigation_planning_governed_at',
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
