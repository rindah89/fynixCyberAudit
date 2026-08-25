<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseClosureReportReviewDecision;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseClosureReport;
use App\Models\ComplianceCaseClosureReportReview;
use App\Models\ComplianceCaseEvent;
use App\Models\User;
use App\Services\ComplianceCaseClosureReportDownload;
use App\Support\CanonicalJson;
use App\Support\Enterprise;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComplianceCaseClosureReportReviewManager
{
    public function review(User $actor, ComplianceCaseClosureReport $report, array $data): ComplianceCaseClosureReportReview
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $report, $data): ComplianceCaseClosureReportReview {
            $caseId = ComplianceCaseClosureReport::query()->whereKey($report->id)->value('compliance_case_id');
            $case = ComplianceCase::query()->lockForUpdate()->findOrFail($caseId);
            abort_unless($actor->can('Manage Compliance Cases') && $actor->can('view', $case), 403);
            if ($case->investigation_reporting_governed_at === null || $case->status !== ComplianceCaseStatus::Closed) {
                throw ValidationException::withMessages(['case' => 'Closure-package review requires a governed closed compliance case.']);
            }
            $reports = ComplianceCaseClosureReport::query()->where('compliance_case_id', $case->id)
                ->orderBy('version')->lockForUpdate()->get();
            $locked = $reports->firstWhere('id', $report->id)
                ?? throw ValidationException::withMessages(['report' => 'The selected closure report is not contained by this case.']);
            if ($reports->last()?->id !== $locked->id) {
                throw ValidationException::withMessages(['report' => 'Only the latest closure report may be reviewed.']);
            }
            $reviews = ComplianceCaseClosureReportReview::query()
                ->whereIn('compliance_case_closure_report_id', $reports->pluck('id'))->lockForUpdate()->get();
            if ($reviews->contains('compliance_case_closure_report_id', $locked->id)) {
                throw ValidationException::withMessages(['report' => 'This closure report already has a retained review.']);
            }
            $closingEvent = ComplianceCaseEvent::query()->where('compliance_case_id', $case->id)
                ->orderByDesc('version')->lockForUpdate()->firstOrFail();
            abort_if(in_array($actor->id, [$locked->generated_by, $closingEvent->recorded_by], true), 403,
                'The closure-report generator and terminal case closer cannot review the retained package.');
            if (isset($data['summary']) && is_string($data['summary'])) {
                $data['summary'] = trim($data['summary']);
            }
            $data = Validator::make($data, self::rules())->validate();
            app(ComplianceCaseClosureReportDownload::class)->verifiedBytes($locked);
            $actor = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($actor->id);
            $review = new ComplianceCaseClosureReportReview([
                'compliance_case_closure_report_id' => $locked->id,
                'decision' => ComplianceCaseClosureReportReviewDecision::from($data['decision']),
                'summary' => $data['summary'], 'reviewed_by' => $actor->id,
                'reviewer_snapshot' => $actor->only(['id', 'name', 'email']),
                'closure_report_snapshot' => ['id' => $locked->id, 'fingerprint' => $locked->fingerprint]
                    + app(ComplianceCaseClosureReportManager::class)->payload($locked),
                'reviewed_at' => now()->startOfSecond(),
            ]);
            $review->fingerprint = hash('sha256', CanonicalJson::encode($this->payload($review)));
            $review->save();

            return $review->load(['reviewer:id,name,email', 'closureReport.generator:id,name,email']);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function payload(ComplianceCaseClosureReportReview $review): array
    {
        return [
            'compliance_case_closure_report_id' => $review->compliance_case_closure_report_id,
            'decision' => $review->decision instanceof \BackedEnum ? $review->decision->value : $review->decision,
            'summary' => $review->summary, 'reviewed_by' => $review->reviewed_by,
            'reviewer_snapshot' => $review->reviewer_snapshot,
            'closure_report_snapshot' => $review->closure_report_snapshot,
            'reviewed_at' => $review->reviewed_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public static function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(ComplianceCaseClosureReportReviewDecision::class)],
            'summary' => 'required|string|max:30000',
            'id' => 'prohibited', 'compliance_case_closure_report_id' => 'prohibited',
            'reviewed_by' => 'prohibited', 'reviewer_snapshot' => 'prohibited',
            'closure_report_snapshot' => 'prohibited', 'reviewed_at' => 'prohibited',
            'fingerprint' => 'prohibited', 'created_at' => 'prohibited', 'updated_at' => 'prohibited',
        ];
    }
}
