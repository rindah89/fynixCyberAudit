<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseInvestigationPlanDecision;
use App\Enums\ComplianceCaseInvestigationProcedureReviewDecision;
use App\Enums\ComplianceCaseInvestigationReportDecision;
use App\Enums\ComplianceCaseInvestigationReportOutcome;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseInvestigationPlan;
use App\Models\ComplianceCaseInvestigationPlanReview;
use App\Models\ComplianceCaseInvestigationProcedureExecution;
use App\Models\ComplianceCaseInvestigationProcedureReview;
use App\Models\ComplianceCaseInvestigationReport;
use App\Models\ComplianceCaseInvestigationReportReview;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Enterprise;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComplianceCaseInvestigationReportManager
{
    public const MAX_SNAPSHOT_BYTES = 10_000_000;

    public function submit(User $actor, ComplianceCase $case, array $data): ComplianceCaseInvestigationReport
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $case, $data): ComplianceCaseInvestigationReport {
            $locked = ComplianceCase::query()->lockForUpdate()->findOrFail($case->id);
            $isManager = $actor->can('Manage Compliance Cases');
            $isInvestigator = $actor->can('Investigate Compliance Cases') && $locked->assigned_to === $actor->id;
            abort_unless($isManager || $isInvestigator, 403);
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $locked);
            $this->assertReportable($locked);
            $data = $this->validated($data, self::submitRules());
            [$events, $plan, $planReview, $executions, $procedureReviews] = $this->lockInvestigationContext($locked);
            [$reports, $reportReviews] = $this->lockReports($locked);
            if ($reports->count() >= 20) {
                throw ValidationException::withMessages(['case' => 'A governed compliance case is limited to 20 investigation-report versions.']);
            }
            $latestReport = $reports->last();
            $latestReview = $latestReport === null ? null : $reportReviews->get($latestReport->id);
            $currentEventFingerprint = $events->last()?->fingerprint;
            if ($latestReport !== null && ($latestReview === null
                || ($latestReview->decision === ComplianceCaseInvestigationReportDecision::Approved
                    && data_get($latestReport->report_snapshot, 'case.event.fingerprint') === $currentEventFingerprint))) {
                throw ValidationException::withMessages(['report' => 'A replacement report requires a rejected or stale prior report.']);
            }
            $latestExecutions = $this->approvedLatestExecutions($plan, $executions, $procedureReviews);
            $actor = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($actor->id);
            $authoredAt = now()->startOfSecond();
            $snapshot = $this->reportSnapshot($locked, $events->last(), $plan, $planReview, $latestExecutions, $procedureReviews);
            if (strlen(CanonicalJson::encode($snapshot)) > self::MAX_SNAPSHOT_BYTES) {
                throw ValidationException::withMessages(['report' => 'The governed investigation-report snapshot exceeds 10,000,000 serialized bytes.']);
            }
            $report = new ComplianceCaseInvestigationReport([
                'compliance_case_id' => $locked->id, 'version' => $reports->count() + 1,
                'outcome' => ComplianceCaseInvestigationReportOutcome::from($data['outcome']),
                'executive_summary' => $data['executive_summary'], 'analysis' => $data['analysis'],
                'findings' => $data['findings'], 'recommendations' => $data['recommendations'],
                'report_snapshot' => $snapshot, 'authored_by' => $actor->id,
                'author_snapshot' => $actor->only(['id', 'name', 'email']), 'authored_at' => $authoredAt,
            ]);
            $report->fingerprint = hash('sha256', CanonicalJson::encode($this->reportPayload($report)));
            $report->save();

            return $report->load(['author:id,name,email', 'review.reviewer:id,name,email']);
        }, 3);
    }

    public function review(User $actor, ComplianceCaseInvestigationReport $report, array $data): ComplianceCaseInvestigationReportReview
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $report, $data): ComplianceCaseInvestigationReportReview {
            $caseId = ComplianceCaseInvestigationReport::query()->whereKey($report->id)->value('compliance_case_id');
            $case = ComplianceCase::query()->lockForUpdate()->findOrFail($caseId);
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $case);
            abort_unless($actor->can('Manage Compliance Cases'), 403);
            $this->assertReportable($case);
            [$events, $plan, $planReview, $executions, $procedureReviews] = $this->lockInvestigationContext($case);
            [$reports, $reportReviews] = $this->lockReports($case);
            $locked = $reports->firstWhere('id', $report->id) ?? throw ValidationException::withMessages(['report' => 'The selected report is not contained by this case.']);
            if ($reports->last()?->id !== $locked->id) {
                throw ValidationException::withMessages(['report' => 'Only the latest investigation report may be reviewed.']);
            }
            if ($reportReviews->has($locked->id)) {
                throw ValidationException::withMessages(['report' => 'This investigation report already has a retained review.']);
            }
            $excluded = $executions->pluck('executed_by')->merge($procedureReviews->pluck('reviewed_by'))
                ->push($locked->authored_by)->push($case->assigned_to)->filter()->unique();
            abort_if($excluded->contains($actor->id), 403, 'The report author, assigned investigator, procedure executors, and procedure reviewers cannot approve the final investigation report.');
            $data = $this->validated($data, self::reviewRules());
            $decision = ComplianceCaseInvestigationReportDecision::from($data['decision']);
            if ($decision === ComplianceCaseInvestigationReportDecision::Approved) {
                $latestExecutions = $this->approvedLatestExecutions($plan, $executions, $procedureReviews);
                $current = $this->sourceFingerprints($events->last(), $plan, $planReview, $latestExecutions, $procedureReviews);
                if (data_get($locked->report_snapshot, 'source_fingerprints') !== $current) {
                    throw ValidationException::withMessages(['report' => 'Approval requires the unchanged current case, plan, and latest reviewed procedure context.']);
                }
            }
            $actor = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($actor->id);
            $reviewedAt = now()->startOfSecond();
            $review = new ComplianceCaseInvestigationReportReview([
                'compliance_case_investigation_report_id' => $locked->id, 'decision' => $decision,
                'summary' => $data['summary'], 'reviewed_by' => $actor->id,
                'reviewer_snapshot' => $actor->only(['id', 'name', 'email']),
                'report_snapshot' => ['id' => $locked->id, 'fingerprint' => $locked->fingerprint] + $this->reportPayload($locked),
                'reviewed_at' => $reviewedAt,
            ]);
            $review->fingerprint = hash('sha256', CanonicalJson::encode($this->reviewPayload($review)));
            $review->save();

            return $review->load(['reviewer:id,name,email', 'report.author:id,name,email']);
        }, 3);
    }

    public function history(User $actor, ComplianceCase $case, int $perPage): LengthAwarePaginator
    {
        Enterprise::assertEnabled('compliance_cases');
        $case = ComplianceCase::query()->findOrFail($case->id);
        abort_unless($actor->can('view', $case), 403);

        return $case->investigationReports()->with(['author:id,name,email', 'review.reviewer:id,name,email'])->paginate($perPage);
    }

    public function reportPayload(ComplianceCaseInvestigationReport $report): array
    {
        return [
            'compliance_case_id' => $report->compliance_case_id, 'version' => $report->version,
            'outcome' => $report->outcome instanceof \BackedEnum ? $report->outcome->value : $report->outcome,
            'executive_summary' => $report->executive_summary, 'analysis' => $report->analysis,
            'findings' => $report->findings, 'recommendations' => $report->recommendations,
            'report_snapshot' => $report->report_snapshot, 'authored_by' => $report->authored_by,
            'author_snapshot' => $report->author_snapshot, 'authored_at' => $report->authored_at?->toIso8601String(),
        ];
    }

    public function reviewPayload(ComplianceCaseInvestigationReportReview $review): array
    {
        return [
            'compliance_case_investigation_report_id' => $review->compliance_case_investigation_report_id,
            'decision' => $review->decision instanceof \BackedEnum ? $review->decision->value : $review->decision,
            'summary' => $review->summary, 'reviewed_by' => $review->reviewed_by,
            'reviewer_snapshot' => $review->reviewer_snapshot, 'report_snapshot' => $review->report_snapshot,
            'reviewed_at' => $review->reviewed_at?->toIso8601String(),
        ];
    }

    private function assertReportable(ComplianceCase $case): void
    {
        if ($case->investigation_reporting_governed_at === null) {
            throw ValidationException::withMessages(['case' => 'Legacy cases are excluded from governed investigation reporting.']);
        }
        if (! in_array($case->status, [ComplianceCaseStatus::Investigating, ComplianceCaseStatus::ActionRequired], true)) {
            throw ValidationException::withMessages(['case' => 'Investigation reports may be governed only during investigation or action-required work.']);
        }
    }

    private function lockInvestigationContext(ComplianceCase $case): array
    {
        $events = ComplianceCaseEvent::query()->where('compliance_case_id', $case->id)->orderBy('version')->lockForUpdate()->get();
        $plans = ComplianceCaseInvestigationPlan::query()->where('compliance_case_id', $case->id)->orderBy('version')->lockForUpdate()->get();
        $planReviews = ComplianceCaseInvestigationPlanReview::query()->whereIn('compliance_case_investigation_plan_id', $plans->pluck('id'))
            ->orderBy('compliance_case_investigation_plan_id')->lockForUpdate()->get()->keyBy('compliance_case_investigation_plan_id');
        $executions = ComplianceCaseInvestigationProcedureExecution::query()->where('compliance_case_id', $case->id)
            ->orderBy('procedure_index')->orderBy('version')->lockForUpdate()->get();
        $procedureReviews = ComplianceCaseInvestigationProcedureReview::query()
            ->whereIn('compliance_case_investigation_procedure_execution_id', $executions->pluck('id'))
            ->orderBy('compliance_case_investigation_procedure_execution_id')->lockForUpdate()->get()
            ->keyBy('compliance_case_investigation_procedure_execution_id');
        $plan = $plans->last();
        $planReview = $plan === null ? null : $planReviews->get($plan->id);
        if ($plan === null || $planReview?->decision !== ComplianceCaseInvestigationPlanDecision::Approved) {
            throw ValidationException::withMessages(['report' => 'Investigation reporting requires the latest approved investigation plan.']);
        }

        return [$events, $plan, $planReview, $executions, $procedureReviews];
    }

    private function lockReports(ComplianceCase $case): array
    {
        $reports = ComplianceCaseInvestigationReport::query()->where('compliance_case_id', $case->id)->orderBy('version')->lockForUpdate()->get();
        $reviews = ComplianceCaseInvestigationReportReview::query()->whereIn('compliance_case_investigation_report_id', $reports->pluck('id'))
            ->orderBy('compliance_case_investigation_report_id')->lockForUpdate()->get()->keyBy('compliance_case_investigation_report_id');

        return [$reports, $reviews];
    }

    private function approvedLatestExecutions(ComplianceCaseInvestigationPlan $plan, Collection $executions, Collection $reviews): Collection
    {
        $latest = $executions->where('compliance_case_investigation_plan_id', $plan->id)->groupBy('procedure_index')->map->last();
        $approved = $latest->filter(fn (ComplianceCaseInvestigationProcedureExecution $execution): bool => $reviews->get($execution->id)?->decision === ComplianceCaseInvestigationProcedureReviewDecision::Approved);
        if ($approved->keys()->map(fn ($index): int => (int) $index)->sort()->values()->all() !== range(1, count($plan->procedures))) {
            throw ValidationException::withMessages(['report' => 'Every latest approved-plan procedure conclusion requires supervisory approval before reporting.']);
        }

        return $approved->sortKeys();
    }

    public function reportSnapshot(ComplianceCase $case, ?ComplianceCaseEvent $event, ComplianceCaseInvestigationPlan $plan, ComplianceCaseInvestigationPlanReview $planReview, Collection $executions, Collection $reviews): array
    {
        $planManager = app(ComplianceCaseInvestigationPlanManager::class);
        $executionManager = app(ComplianceCaseInvestigationProcedureExecutionManager::class);

        return [
            'case' => $planManager->caseSnapshot($case, $event),
            'approved_plan' => ['id' => $plan->id, 'fingerprint' => $plan->fingerprint] + $planManager->planPayload($plan) + [
                'review' => ['id' => $planReview->id, 'fingerprint' => $planReview->fingerprint] + $planManager->reviewPayload($planReview),
            ],
            'procedure_conclusions' => $executions->map(function (ComplianceCaseInvestigationProcedureExecution $execution) use ($reviews, $executionManager): array {
                $review = $reviews->get($execution->id);

                return ['id' => $execution->id, 'fingerprint' => $execution->fingerprint]
                    + $executionManager->payload($execution)
                    + ['supervisory_review' => $review === null ? null : (
                        ['id' => $review->id, 'fingerprint' => $review->fingerprint]
                        + $executionManager->reviewPayload($review)
                    )];
            })->values()->all(),
            'source_fingerprints' => $this->sourceFingerprints($event, $plan, $planReview, $executions, $reviews),
        ];
    }

    public function sourceFingerprints(?ComplianceCaseEvent $event, ComplianceCaseInvestigationPlan $plan, ComplianceCaseInvestigationPlanReview $planReview, Collection $executions, Collection $reviews): array
    {
        return [
            'case_event' => $event?->fingerprint, 'plan' => $plan->fingerprint, 'plan_review' => $planReview->fingerprint,
            'procedure_conclusions' => $executions->pluck('fingerprint')->values()->all(),
            'procedure_reviews' => $executions->map(fn ($execution) => $reviews->get($execution->id)?->fingerprint)->values()->all(),
        ];
    }

    private function validated(array $data, array $rules): array
    {
        foreach (['executive_summary', 'analysis', 'findings', 'recommendations', 'summary'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }

        return Validator::make($data, $rules)->validate();
    }

    public static function submitRules(): array
    {
        return [
            'outcome' => ['required', Rule::enum(ComplianceCaseInvestigationReportOutcome::class)],
            'executive_summary' => 'required|string|max:30000', 'analysis' => 'required|string|max:30000',
            'findings' => 'required|string|max:30000', 'recommendations' => 'required|string|max:30000',
            'id' => 'prohibited', 'compliance_case_id' => 'prohibited', 'version' => 'prohibited', 'report_snapshot' => 'prohibited',
            'authored_by' => 'prohibited', 'author_snapshot' => 'prohibited', 'authored_at' => 'prohibited',
            'fingerprint' => 'prohibited', 'created_at' => 'prohibited', 'updated_at' => 'prohibited',
        ];
    }

    public static function reviewRules(): array
    {
        return [
            'decision' => ['required', Rule::enum(ComplianceCaseInvestigationReportDecision::class)], 'summary' => 'required|string|max:30000',
            'id' => 'prohibited', 'compliance_case_investigation_report_id' => 'prohibited', 'reviewed_by' => 'prohibited',
            'reviewer_snapshot' => 'prohibited', 'report_snapshot' => 'prohibited', 'reviewed_at' => 'prohibited',
            'fingerprint' => 'prohibited', 'created_at' => 'prohibited', 'updated_at' => 'prohibited',
        ];
    }
}
