<?php

namespace App\Services;

use App\Enums\Applicability;
use App\Enums\AuditCloseoutDecision;
use App\Enums\AuditOpinion;
use App\Enums\Effectiveness;
use App\Enums\ResponseStatus;
use App\Enums\WorkflowStatus;
use App\Models\Audit;
use App\Models\AuditCloseoutReview;
use App\Models\AuditCloseoutSubmission;
use App\Models\AuditItem;
use App\Models\Control;
use App\Models\DataRequest;
use App\Models\Implementation;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuditCloseoutManager
{
    public function submit(Audit $audit, User $actor, array $data): AuditCloseoutSubmission
    {
        return DB::transaction(function () use ($audit, $actor, $data): AuditCloseoutSubmission {
            $locked = Audit::query()->lockForUpdate()->findOrFail($audit->id);
            $this->authorizeSubmit($locked, $actor);
            if ($locked->status !== WorkflowStatus::INPROGRESS) {
                throw ValidationException::withMessages(['audit' => 'Only an in-progress audit can be submitted for closeout.']);
            }
            $validated = Validator::make($data, self::submissionRules())->validate();
            $baseline = $locked->engagementBaseline()->lockForUpdate()->first();
            if (! $baseline) {
                throw ValidationException::withMessages(['audit' => 'Governed closeout currently requires an approved-plan engagement baseline.']);
            }
            $submissions = $locked->closeoutSubmissions()->orderBy('version')->lockForUpdate()->get();
            $reviews = AuditCloseoutReview::query()->whereIn('audit_closeout_submission_id', $submissions->modelKeys())->orderBy('id')->lockForUpdate()->get()->keyBy('audit_closeout_submission_id');
            if ($submissions->last() && ! $reviews->has($submissions->last()->id)) {
                throw ValidationException::withMessages(['audit' => 'The latest closeout submission is still awaiting review.']);
            }
            if ($reviews->contains(fn (AuditCloseoutReview $review): bool => $review->decision === AuditCloseoutDecision::Approved)) {
                throw ValidationException::withMessages(['audit' => 'This audit already has an approved closeout.']);
            }

            $items = $locked->auditItems()->orderBy('id')->lockForUpdate()->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['audit_items' => 'Closeout requires at least one completed audit item.']);
            }
            $this->assertFieldworkComplete($items);
            if ($items->count() > 250) {
                throw ValidationException::withMessages(['audit_items' => 'A governed closeout is bounded to 250 audit items. Split larger scopes into separate engagements.']);
            }
            $requests = $locked->dataRequest()->orderBy('id')->lockForUpdate()->get();
            $this->assertRequestsResolved($requests);
            if ($requests->count() > 500) {
                throw ValidationException::withMessages(['data_requests' => 'A governed closeout is bounded to 500 data requests. Split larger scopes into separate engagements.']);
            }
            $procedures = $locked->procedures()->with('execution.review')->orderBy('id')->lockForUpdate()->get();
            if ($procedures->count() > 250) {
                throw ValidationException::withMessages(['audit_procedures' => 'Governed closeout is bounded to 250 procedure versions.']);
            }
            if ($procedures->contains(fn ($procedure): bool => ! $procedure->execution)) {
                throw ValidationException::withMessages(['audit_procedures' => 'Every defined audit procedure must be executed before closeout.']);
            }
            if ($procedures->contains(fn ($procedure): bool => ! $procedure->execution?->review)) {
                throw ValidationException::withMessages(['audit_procedures' => 'Every executed workpaper must receive supervisory review before closeout.']);
            }
            if ($procedures->groupBy('code')->contains(fn (Collection $versions): bool => $versions->sortBy('version')->last()->execution->review->decision->value !== 'approved')) {
                throw ValidationException::withMessages(['audit_procedures' => 'The latest version of every workpaper must have an approved supervisory review before closeout.']);
            }
            DB::table('audit_user')->where('audit_id', $locked->id)->orderBy('user_id')->lockForUpdate()->get();
            $memberIds = $locked->members()->orderBy('users.id')->lockForUpdate()->pluck('users.id')->map(fn ($id): int => (int) $id)->all();
            User::query()->whereKey(collect($memberIds)->push($locked->manager_id)->filter()->unique())->orderBy('id')->lockForUpdate()->get();

            $auditableSnapshots = $this->lockAuditableSnapshots($items);
            $auditSnapshot = [
                'id' => $locked->id, 'title' => $locked->title, 'description' => $locked->description,
                'audit_type' => $locked->audit_type, 'status' => $locked->status->value,
                'start_date' => $locked->start_date->toDateString(), 'end_date' => $locked->end_date->toDateString(),
                'manager_id' => $locked->manager_id, 'program_id' => $locked->program_id, 'member_ids' => $memberIds,
            ];
            $itemSnapshots = $items->map(fn (AuditItem $item): array => [
                ...$item->only(['id', 'audit_id', 'user_id', 'auditable_id', 'auditable_type', 'auditor_notes', 'status', 'effectiveness', 'applicability']),
                'auditable_snapshot' => $auditableSnapshots->get($item->auditable_type.':'.$item->auditable_id),
            ])->all();
            $requestSnapshots = $requests->map(fn (DataRequest $request): array => $request->only(['id', 'code', 'audit_item_id', 'created_by_id', 'assigned_to_id', 'status', 'details', 'created_at', 'updated_at']))->all();
            $procedureSnapshots = $this->procedureSnapshots($procedures);
            $effortSnapshots = $this->lockEffortSnapshots($locked);
            $findingSnapshots = $this->lockFindingSnapshots($locked, requireResponses: true);
            $submittedAt = now();
            $payload = [
                'audit_snapshot' => $auditSnapshot,
                'engagement_baseline_snapshot' => $baseline->only(['id', 'audit_id', 'audit_plan_item_id', 'objective', 'scope', 'exclusions', 'team_user_ids', 'audit_snapshot', 'plan_snapshot', 'entity_assessment_snapshot', 'launched_by', 'launched_at', 'fingerprint']),
                'audit_item_snapshots' => $itemSnapshots,
                'data_request_snapshots' => $requestSnapshots,
                'audit_procedure_snapshots' => $procedureSnapshots,
                'audit_effort_snapshots' => $effortSnapshots,
                'audit_finding_snapshots' => $findingSnapshots,
                'opinion' => $validated['opinion'],
                'executive_summary' => $validated['executive_summary'],
                'scope_limitations' => $validated['scope_limitations'] ?? null,
                'significant_matters' => $validated['significant_matters'],
                'recommendations_summary' => $validated['recommendations_summary'],
                'submitted_by' => $actor->id,
                'submitted_at' => $submittedAt->toIso8601String(),
                'version' => ((int) $submissions->max('version')) + 1,
            ];

            return $locked->closeoutSubmissions()->create($payload + [
                'submitted_at' => $submittedAt,
                'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            ])->load(['submitter:id,name', 'audit.engagementBaseline']);
        }, 3);
    }

    public function review(AuditCloseoutSubmission $submission, User $actor, array $data): AuditCloseoutReview
    {
        $reportDisk = setting('storage.driver', 'private');
        $reportPath = 'audit_reports/governed/'.Str::uuid().'.pdf';
        $stored = false;
        try {
            $review = DB::transaction(function () use ($submission, $actor, $data, $reportDisk, $reportPath, &$stored): AuditCloseoutReview {
                $auditId = AuditCloseoutSubmission::query()->findOrFail($submission->id)->audit_id;
                $audit = Audit::query()->lockForUpdate()->findOrFail($auditId);
                $locked = AuditCloseoutSubmission::query()->where('audit_id', $audit->id)->lockForUpdate()->findOrFail($submission->id);
                $this->authorizeReview($audit, $locked, $actor);
                if ($audit->status !== WorkflowStatus::INPROGRESS || $locked->review()->exists()) {
                    throw ValidationException::withMessages(['submission' => 'Only an unreviewed submission for an in-progress audit can be reviewed.']);
                }
                if ($audit->closeoutSubmissions()->max('version') !== $locked->version) {
                    throw ValidationException::withMessages(['submission' => 'Only the latest closeout submission can be reviewed.']);
                }
                $this->assertSubmissionSourcesUnchanged($audit, $locked);
                $validated = Validator::make($data, self::reviewRules())->validate();
                $reviewedAt = now();
                $reportSnapshot = [
                    'audit_id' => $audit->id,
                    'submission_id' => $locked->id,
                    'submission_version' => $locked->version,
                    'submission_fingerprint' => $locked->fingerprint,
                    'opinion' => $locked->opinion->value,
                    'executive_summary' => $locked->executive_summary,
                    'scope_limitations' => $locked->scope_limitations,
                    'significant_matters' => $locked->significant_matters,
                    'recommendations_summary' => $locked->recommendations_summary,
                    'audit_snapshot' => $locked->audit_snapshot,
                    'engagement_baseline_snapshot' => $locked->engagement_baseline_snapshot,
                    'audit_item_snapshots' => $locked->audit_item_snapshots,
                    'data_request_snapshots' => $locked->data_request_snapshots,
                    'audit_procedure_snapshots' => $locked->audit_procedure_snapshots,
                    'audit_effort_snapshots' => $locked->audit_effort_snapshots,
                    'audit_finding_snapshots' => $locked->audit_finding_snapshots,
                    'decision' => $validated['decision'],
                    'review_summary' => $validated['review_summary'],
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => $reviewedAt->toIso8601String(),
                ];
                $reportMetadata = ['report_disk' => null, 'report_path' => null, 'report_size' => null, 'report_sha256' => null];
                if (AuditCloseoutDecision::from($validated['decision']) === AuditCloseoutDecision::Approved) {
                    $bytes = Pdf::loadView('reports.governed-audit-closeout', ['report' => $reportSnapshot])->output();
                    if (! Storage::disk($reportDisk)->put($reportPath, $bytes, ['visibility' => 'private'])) {
                        throw ValidationException::withMessages(['report' => 'The approved final report could not be retained.']);
                    }
                    $stored = true;
                    $reportMetadata = ['report_disk' => $reportDisk, 'report_path' => $reportPath, 'report_size' => strlen($bytes), 'report_sha256' => hash('sha256', $bytes)];
                }
                $payload = $reportSnapshot + $reportMetadata;
                $review = $locked->review()->create([
                    'decision' => $validated['decision'], 'review_summary' => $validated['review_summary'],
                    'report_snapshot' => $reportSnapshot, 'reviewed_by' => $actor->id, 'reviewed_at' => $reviewedAt,
                    ...$reportMetadata, 'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                ]);
                if ($review->decision === AuditCloseoutDecision::Approved) {
                    $audit->update(['status' => WorkflowStatus::COMPLETED]);
                }

                return $review->load(['reviewer:id,name', 'submission.audit']);
            }, 3);

            return $review;
        } catch (\Throwable $exception) {
            if ($stored) {
                Storage::disk($reportDisk)->delete($reportPath);
            }
            throw $exception;
        }
    }

    public static function submissionRules(): array
    {
        return [
            'opinion' => ['required', Rule::enum(AuditOpinion::class)],
            'executive_summary' => ['required', 'string', 'max:30000'],
            'scope_limitations' => ['nullable', 'string', 'max:30000'],
            'significant_matters' => ['required', 'string', 'max:30000'],
            'recommendations_summary' => ['required', 'string', 'max:30000'],
        ];
    }

    public static function reviewRules(): array
    {
        return ['decision' => ['required', Rule::enum(AuditCloseoutDecision::class)], 'review_summary' => ['required', 'string', 'max:30000']];
    }

    private function authorizeSubmit(Audit $audit, User $actor): void
    {
        if ($audit->manager_id === $actor->id || $actor->can('Update Audits')) {
            return;
        }
        abort(403, 'You cannot submit this audit for closeout.');
    }

    private function authorizeReview(Audit $audit, AuditCloseoutSubmission $submission, User $actor): void
    {
        if ($actor->can('Update Audits') && $audit->manager_id !== $actor->id && $submission->submitted_by !== $actor->id) {
            return;
        }
        abort(403, 'Closeout review requires an independent user with Update Audits.');
    }

    private function assertFieldworkComplete(Collection $items): void
    {
        foreach ($items as $item) {
            if ($item->status !== WorkflowStatus::COMPLETED || blank($item->auditor_notes) || mb_strlen((string) $item->auditor_notes) > 30000 || $item->effectiveness === Effectiveness::UNKNOWN) {
                throw ValidationException::withMessages(['audit_items' => 'Every audit item must be completed with auditor notes and an effectiveness conclusion.']);
            }
            if ($item->auditable_type === Control::class && $item->applicability === Applicability::UNKNOWN) {
                throw ValidationException::withMessages(['audit_items' => 'Every control audit item requires an applicability conclusion.']);
            }
        }
    }

    private function assertRequestsResolved(Collection $requests): void
    {
        foreach ($requests as $request) {
            if (mb_strlen((string) $request->details) > 30000) {
                throw ValidationException::withMessages(['data_requests' => 'Data-request details cannot exceed 30,000 characters at governed closeout.']);
            }
            if (! in_array($request->status, [ResponseStatus::ACCEPTED->value, ResponseStatus::REJECTED->value], true)) {
                throw ValidationException::withMessages(['data_requests' => 'Every audit data request must be accepted or rejected before closeout.']);
            }
        }
    }

    private function assertSubmissionSourcesUnchanged(Audit $audit, AuditCloseoutSubmission $submission): void
    {
        $items = $audit->auditItems()->orderBy('id')->lockForUpdate()->get();
        $requests = $audit->dataRequest()->orderBy('id')->lockForUpdate()->get();
        $procedures = $audit->procedures()->with('execution.review')->orderBy('id')->lockForUpdate()->get();
        DB::table('audit_user')->where('audit_id', $audit->id)->orderBy('user_id')->lockForUpdate()->get();
        $memberIds = $audit->members()->orderBy('users.id')->lockForUpdate()->pluck('users.id')->map(fn ($id): int => (int) $id)->all();
        $auditableSnapshots = $this->lockAuditableSnapshots($items);

        $currentAudit = [
            'id' => $audit->id, 'title' => $audit->title, 'description' => $audit->description,
            'audit_type' => $audit->audit_type, 'status' => $audit->status->value,
            'start_date' => $audit->start_date->toDateString(), 'end_date' => $audit->end_date->toDateString(),
            'manager_id' => $audit->manager_id, 'program_id' => $audit->program_id, 'member_ids' => $memberIds,
        ];
        $currentItems = $items->map(fn (AuditItem $item): array => [
            ...$item->only(['id', 'audit_id', 'user_id', 'auditable_id', 'auditable_type', 'auditor_notes', 'status', 'effectiveness', 'applicability']),
            'auditable_snapshot' => $auditableSnapshots->get($item->auditable_type.':'.$item->auditable_id),
        ])->all();
        $currentRequests = $requests->map(fn (DataRequest $request): array => $request->only(['id', 'code', 'audit_item_id', 'created_by_id', 'assigned_to_id', 'status', 'details', 'created_at', 'updated_at']))->all();
        $currentProcedures = $this->procedureSnapshots($procedures);
        $currentEffort = $this->lockEffortSnapshots($audit);
        $currentFindings = $this->lockFindingSnapshots($audit, requireResponses: false);

        if ($this->canonicalSnapshot($currentAudit) !== $submission->audit_snapshot
            || $this->canonicalSnapshot($currentItems) !== $submission->audit_item_snapshots
            || $this->canonicalSnapshot($currentRequests) !== $submission->data_request_snapshots
            || $this->canonicalSnapshot($currentProcedures) !== ($submission->audit_procedure_snapshots ?? [])
            || $this->canonicalSnapshot($currentEffort) !== ($submission->audit_effort_snapshots ?? ['budgets' => [], 'time_entries' => [], 'summary' => ['planned_minutes' => 0, 'actual_minutes' => 0, 'variance_minutes' => 0, 'allocations' => []]])
            || $this->canonicalSnapshot($currentFindings) !== ($submission->audit_finding_snapshots ?? [])) {
            throw ValidationException::withMessages([
                'submission' => 'The captured audit scope or fieldwork changed after submission. Reject this version and submit a fresh closeout snapshot.',
            ]);
        }
    }

    private function canonicalSnapshot(array $snapshot): array
    {
        return json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    private function procedureSnapshots(Collection $procedures): array
    {
        return $procedures->map(fn ($procedure): array => [
            ...$procedure->only(['id', 'audit_id', 'audit_item_id', 'version', 'code', 'title', 'objective', 'steps', 'method', 'population_description', 'planned_sample_size', 'assigned_to', 'due_at', 'status', 'created_by', 'created_at']),
            'execution' => $procedure->execution?->only(['id', 'outcome', 'result', 'exceptions', 'sample_tested', 'evidence_reference', 'evidence_manifest', 'procedure_snapshot', 'executed_by', 'executed_at', 'fingerprint']),
            'supervisory_review' => $procedure->execution?->review?->only(['id', 'audit_procedure_execution_id', 'decision', 'review_summary', 'execution_snapshot', 'reviewed_by', 'reviewed_at', 'fingerprint']),
        ])->all();
    }

    private function lockEffortSnapshots(Audit $audit): array
    {
        $budgets = $audit->effortBudgets()->with(['procedure:id,code,title', 'user:id,name', 'setter:id,name'])->orderBy('id')->lockForUpdate()->get();
        $entries = $audit->timeEntries()->with(['procedure:id,code,title', 'user:id,name', 'entrant:id,name'])->orderBy('id')->lockForUpdate()->get();

        return [
            'budgets' => $budgets->map(fn ($budget): array => $budget->only(['id', 'audit_id', 'audit_procedure_id', 'user_id', 'version', 'planned_minutes', 'rationale', 'allocation_snapshot', 'set_by', 'set_at', 'fingerprint']))->all(),
            'time_entries' => $entries->map(fn ($entry): array => $entry->only(['id', 'audit_id', 'audit_procedure_id', 'user_id', 'entry_type', 'reverses_time_entry_id', 'work_date', 'minutes', 'activity', 'notes', 'source_reference', 'budget_snapshot', 'procedure_snapshot', 'entered_by', 'entered_at', 'fingerprint']))->all(),
            'summary' => app(AuditEffortManager::class)->summary($audit),
        ];
    }

    private function lockFindingSnapshots(Audit $audit, bool $requireResponses): array
    {
        $findings = $audit->governedFindings()->with(['responses' => fn ($query) => $query->orderBy('version')])->orderBy('id')->lockForUpdate()->get();
        if ($findings->count() > 500) {
            throw ValidationException::withMessages(['audit_findings' => 'Governed closeout is bounded to 500 findings.']);
        }
        if ($requireResponses && $findings->contains(fn ($finding): bool => $finding->responses->isEmpty())) {
            throw ValidationException::withMessages(['audit_findings' => 'Every governed audit finding requires an accountable management response before closeout.']);
        }

        $snapshots = $findings->map(fn ($finding): array => [
            ...$finding->only(['id', 'audit_id', 'audit_item_id', 'code', 'title', 'severity', 'condition', 'criteria', 'cause', 'effect', 'recommendation', 'accountable_owner_id', 'source_snapshot', 'raised_by', 'raised_at', 'fingerprint']),
            'responses' => $finding->responses->map(fn ($response): array => $response->only(['id', 'audit_finding_id', 'version', 'position', 'response', 'action_plan', 'target_date', 'finding_snapshot', 'responded_by', 'responded_at', 'fingerprint']))->all(),
        ])->all();
        if (strlen(json_encode($snapshots, JSON_THROW_ON_ERROR)) > AuditFindingManager::MAX_EVIDENCE_BYTES) {
            throw ValidationException::withMessages(['audit_findings' => 'Governed finding and management-response evidence exceeds the 5,000,000-byte closeout bound.']);
        }

        return $snapshots;
    }

    private function lockAuditableSnapshots(Collection $items): Collection
    {
        $snapshots = collect();
        foreach ([Control::class, Implementation::class] as $type) {
            $ids = $items->where('auditable_type', $type)->pluck('auditable_id')->filter()->unique()->sort()->values();
            if ($ids->isEmpty()) {
                continue;
            }
            $models = $type::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($models->count() !== $ids->count()) {
                throw ValidationException::withMessages(['audit_items' => 'Every audited control or implementation must remain available at closeout.']);
            }
            foreach ($models as $model) {
                $snapshots->put($type.':'.$model->id, $model->only(['id', 'code', 'title', 'status', 'effectiveness', 'applicability', 'updated_at']));
            }
        }

        return $snapshots;
    }
}
