<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseClosureReportReviewDecision;
use App\Enums\ComplianceCaseInvestigationReportDecision;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseActionIssue;
use App\Models\ComplianceCaseClosureReport;
use App\Models\ComplianceCaseClosureReportReview;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseEvidenceSubmission;
use App\Models\ComplianceCaseIntake;
use App\Models\ComplianceCaseIntakeDisposition;
use App\Models\ComplianceCaseInterview;
use App\Models\ComplianceCaseInterviewEvent;
use App\Models\ComplianceCaseInvestigationPlan;
use App\Models\ComplianceCaseInvestigationPlanReview;
use App\Models\ComplianceCaseInvestigationProcedureExecution;
use App\Models\ComplianceCaseInvestigationProcedureReview;
use App\Models\ComplianceCaseInvestigationReport;
use App\Models\ComplianceCaseInvestigationReportReview;
use App\Models\ComplianceCaseLegalHold;
use App\Models\ComplianceCaseLegalHoldAcknowledgement;
use App\Models\ComplianceCaseLegalHoldCustodian;
use App\Models\ComplianceCaseLegalHoldRelease;
use App\Models\GovernanceIssueLifecycle;
use App\Models\GovernanceIssueTransition;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Enterprise;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ComplianceCaseClosureReportManager
{
    public const MAX_SNAPSHOT_BYTES = 10_000_000;

    public const MAX_REPORT_BYTES = 10 * 1024 * 1024;

    /** @param array{executive_summary:string} $data */
    public function generate(User $actor, ComplianceCase $case, array $data): ComplianceCaseClosureReport
    {
        Enterprise::assertEnabled('compliance_cases');
        $disk = setting('storage.driver', 'private');
        $path = 'compliance-case-closure-reports/'.Str::uuid().'.pdf';
        $written = false;

        try {
            return DB::transaction(function () use ($actor, $case, $data, $disk, $path, &$written): ComplianceCaseClosureReport {
                $locked = ComplianceCase::query()->lockForUpdate()->findOrFail($case->id);
                abort_unless($actor->can('Manage Compliance Cases') && $actor->can('view', $locked), 403);
                $data = Validator::make($data, self::rules())->validate();
                if ($locked->investigation_reporting_governed_at === null || $locked->status !== ComplianceCaseStatus::Closed) {
                    throw ValidationException::withMessages(['case' => 'A governed closed compliance case is required for closure reporting.']);
                }
                $existing = ComplianceCaseClosureReport::query()->where('compliance_case_id', $locked->id)
                    ->orderBy('version')->lockForUpdate()->get();
                if ($existing->count() >= 20) {
                    throw ValidationException::withMessages(['case' => 'A governed compliance case is limited to 20 closure-report versions.']);
                }
                $latest = $existing->last();
                if ($latest !== null) {
                    $latestReview = ComplianceCaseClosureReportReview::query()
                        ->where('compliance_case_closure_report_id', $latest->id)->lockForUpdate()->first();
                    if ($latestReview?->decision !== ComplianceCaseClosureReportReviewDecision::Rejected) {
                        throw ValidationException::withMessages(['report' => 'A replacement closure package requires a rejected prior package.']);
                    }
                }
                $actor = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($actor->id);
                $snapshot = $this->lockAndSnapshot($locked, $data['executive_summary']);
                if (strlen(CanonicalJson::encode($snapshot)) > self::MAX_SNAPSHOT_BYTES) {
                    throw ValidationException::withMessages(['case' => 'The governed closure-report snapshot exceeds 10,000,000 serialized bytes.']);
                }
                $bytes = Pdf::loadView('reports.governed-compliance-case-closure', ['report' => $snapshot])->output();
                if (strlen($bytes) > self::MAX_REPORT_BYTES) {
                    throw ValidationException::withMessages(['case' => 'The governed closure-report PDF exceeds 10 MiB.']);
                }
                $written = true;
                if (! Storage::disk($disk)->put($path, $bytes)) {
                    throw ValidationException::withMessages(['case' => 'The governed closure-report PDF could not be retained.']);
                }
                $generatedAt = now()->startOfSecond();
                $report = new ComplianceCaseClosureReport([
                    'compliance_case_id' => $locked->id, 'version' => $existing->count() + 1,
                    'report_snapshot' => $snapshot, 'generated_by' => $actor->id,
                    'generator_snapshot' => $actor->only(['id', 'name', 'email']), 'generated_at' => $generatedAt,
                    'report_disk' => $disk, 'report_path' => $path, 'report_size' => strlen($bytes),
                    'report_sha256' => hash('sha256', $bytes),
                ]);
                $report->fingerprint = hash('sha256', CanonicalJson::encode($this->payload($report)));
                $report->save();

                return $report->load('generator:id,name,email');
            }, 3);
        } catch (\Throwable $exception) {
            if ($written) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            throw $exception;
        }
    }

    public function history(User $actor, ComplianceCase $case, int $perPage): LengthAwarePaginator
    {
        Enterprise::assertEnabled('compliance_cases');
        $case = ComplianceCase::query()->findOrFail($case->id);
        abort_unless($actor->can('view', $case), 403);

        return $case->closureReports()->with(['generator:id,name,email', 'review.reviewer:id,name,email'])->paginate($perPage);
    }

    /** @internal Canonical factory construction for persisted evidence fixtures. */
    public function factorySnapshot(ComplianceCase $case, string $executiveSummary): array
    {
        return DB::transaction(function () use ($case, $executiveSummary): array {
            $locked = ComplianceCase::query()->lockForUpdate()->findOrFail($case->id);

            return $this->lockAndSnapshot($locked, $executiveSummary);
        });
    }

    /** @return array<string,mixed> */
    public function payload(ComplianceCaseClosureReport $report): array
    {
        return [
            'compliance_case_id' => $report->compliance_case_id, 'version' => $report->version,
            'report_snapshot' => $report->report_snapshot, 'generated_by' => $report->generated_by,
            'generator_snapshot' => $report->generator_snapshot, 'generated_at' => $report->generated_at?->toIso8601String(),
            'report_disk' => $report->report_disk, 'report_path' => $report->report_path,
            'report_size' => $report->report_size, 'report_sha256' => $report->report_sha256,
        ];
    }

    /** @return array<string,mixed> */
    private function lockAndSnapshot(ComplianceCase $case, string $executiveSummary): array
    {
        $events = ComplianceCaseEvent::query()->where('compliance_case_id', $case->id)->orderBy('version')->lockForUpdate()->get();
        $intakeDisposition = ComplianceCaseIntakeDisposition::query()->where('compliance_case_id', $case->id)->lockForUpdate()->first();
        $intake = $intakeDisposition === null ? null : ComplianceCaseIntake::query()->lockForUpdate()->findOrFail($intakeDisposition->compliance_case_intake_id);
        $submissions = ComplianceCaseEvidenceSubmission::query()->where('compliance_case_id', $case->id)->orderBy('version')->lockForUpdate()->get();
        $interviews = ComplianceCaseInterview::query()->where('compliance_case_id', $case->id)->orderBy('id')->lockForUpdate()->get();
        $interviewEvents = ComplianceCaseInterviewEvent::query()->whereIn('compliance_case_interview_id', $interviews->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $holds = ComplianceCaseLegalHold::query()->where('compliance_case_id', $case->id)->orderBy('version')->lockForUpdate()->get();
        $custodians = ComplianceCaseLegalHoldCustodian::query()->whereIn('compliance_case_legal_hold_id', $holds->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $acknowledgements = ComplianceCaseLegalHoldAcknowledgement::query()->whereIn('compliance_case_legal_hold_custodian_id', $custodians->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $releases = ComplianceCaseLegalHoldRelease::query()->whereIn('compliance_case_legal_hold_id', $holds->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $plans = ComplianceCaseInvestigationPlan::query()->where('compliance_case_id', $case->id)->orderBy('version')->lockForUpdate()->get();
        $planReviews = ComplianceCaseInvestigationPlanReview::query()->whereIn('compliance_case_investigation_plan_id', $plans->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $executions = ComplianceCaseInvestigationProcedureExecution::query()->where('compliance_case_id', $case->id)->orderBy('procedure_index')->orderBy('version')->lockForUpdate()->get();
        $procedureReviews = ComplianceCaseInvestigationProcedureReview::query()->whereIn('compliance_case_investigation_procedure_execution_id', $executions->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $reports = ComplianceCaseInvestigationReport::query()->where('compliance_case_id', $case->id)->orderBy('version')->lockForUpdate()->get();
        $reportReviews = ComplianceCaseInvestigationReportReview::query()->whereIn('compliance_case_investigation_report_id', $reports->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $latestReport = $reports->last();
        $latestReportReview = $latestReport === null ? null : $reportReviews->firstWhere('compliance_case_investigation_report_id', $latestReport->id);
        if ($latestReport === null || $latestReportReview?->decision !== ComplianceCaseInvestigationReportDecision::Approved) {
            throw ValidationException::withMessages(['case' => 'Closure reporting requires the latest independently approved investigation report.']);
        }
        $issues = ComplianceCaseActionIssue::query()->where('compliance_case_id', $case->id)->orderBy('id')->lockForUpdate()->get();
        $lifecycles = GovernanceIssueLifecycle::query()->where('issue_type', ComplianceCaseActionIssue::class)
            ->whereIn('issue_id', $issues->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        $lifecycleTransitions = GovernanceIssueTransition::query()->whereIn('governance_issue_lifecycle_id', $lifecycles->pluck('id'))->orderBy('id')->lockForUpdate()->get();

        return [
            'executive_summary' => $executiveSummary,
            'intake' => $intake === null ? null : [
                'id' => $intake->id, 'fingerprint' => $intake->fingerprint,
                'disposition_id' => $intakeDisposition->id, 'disposition_fingerprint' => $intakeDisposition->fingerprint,
            ],
            'case' => app(ComplianceCaseInvestigationPlanManager::class)->caseSnapshot($case, $events->last()),
            'events' => $events->map(fn (ComplianceCaseEvent $event): array => $this->eventSnapshot($event))->all(),
            'approved_investigation_report' => ['id' => $latestReport->id, 'fingerprint' => $latestReport->fingerprint]
                + app(ComplianceCaseInvestigationReportManager::class)->reportPayload($latestReport)
                + ['review' => ['id' => $latestReportReview->id, 'fingerprint' => $latestReportReview->fingerprint]
                    + app(ComplianceCaseInvestigationReportManager::class)->reviewPayload($latestReportReview)],
            'source_fingerprints' => [
                'case_events' => $events->pluck('fingerprint')->all(),
                'intake' => $intake?->fingerprint,
                'intake_disposition' => $intakeDisposition?->fingerprint,
                'evidence_submissions' => $submissions->pluck('fingerprint')->all(),
                'interviews' => $interviews->map(
                    fn (ComplianceCaseInterview $interview): string => hash('sha256', CanonicalJson::encode($interview->attributesToArray())),
                )->all(),
                'interview_events' => $interviewEvents->pluck('fingerprint')->all(),
                'legal_holds' => $holds->pluck('fingerprint')->all(),
                'legal_hold_acknowledgements' => $acknowledgements->pluck('fingerprint')->all(),
                'legal_hold_releases' => $releases->pluck('fingerprint')->all(),
                'investigation_plans' => $plans->pluck('fingerprint')->all(),
                'investigation_plan_reviews' => $planReviews->pluck('fingerprint')->all(),
                'procedure_conclusions' => $executions->pluck('fingerprint')->all(),
                'procedure_reviews' => $procedureReviews->pluck('fingerprint')->all(),
                'investigation_reports' => $reports->pluck('fingerprint')->all(),
                'investigation_report_reviews' => $reportReviews->pluck('fingerprint')->all(),
                'action_issues' => $issues->pluck('fingerprint')->all(),
                'action_lifecycle_transitions' => $lifecycleTransitions->map(
                    fn (GovernanceIssueTransition $transition): string => hash('sha256', CanonicalJson::encode($transition->attributesToArray())),
                )->all(),
            ],
            'counts' => [
                'evidence_submissions' => $submissions->count(), 'interviews' => $interviews->count(),
                'legal_holds' => $holds->count(), 'action_issues' => $issues->count(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function eventSnapshot(ComplianceCaseEvent $event): array
    {
        return [
            'id' => $event->id, 'compliance_case_id' => $event->compliance_case_id, 'version' => $event->version,
            'event_type' => $event->event_type, 'before_snapshot' => $event->before_snapshot,
            'after_snapshot' => $event->after_snapshot, 'summary' => $event->summary,
            'recorded_by' => $event->recorded_by, 'recorded_at' => $event->recorded_at->toIso8601String(),
            'fingerprint' => $event->fingerprint,
        ];
    }

    /** @return array<string,mixed> */
    public static function rules(): array
    {
        return [
            'executive_summary' => 'required|string|max:30000',
            'id' => 'prohibited', 'compliance_case_id' => 'prohibited', 'version' => 'prohibited',
            'report_snapshot' => 'prohibited', 'generated_by' => 'prohibited', 'generator_snapshot' => 'prohibited',
            'generated_at' => 'prohibited', 'report_disk' => 'prohibited', 'report_path' => 'prohibited',
            'report_size' => 'prohibited', 'report_sha256' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }
}
