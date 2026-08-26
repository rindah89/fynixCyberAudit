<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseClosureReportReviewManager;
use App\ComplianceCases\ComplianceCaseConflictManager;
use App\Models\ComplianceCaseClosureReport;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ReviewComplianceCaseClosureReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $report = $this->route('report');

        return Enterprise::enabled('compliance_cases') && $report instanceof ComplianceCaseClosureReport
            && $this->user()?->can('update', $report) === true
            && ! app(ComplianceCaseConflictManager::class)->isRecused((int) $this->user()->id, (int) $report->compliance_case_id);
    }

    public function rules(): array
    {
        return ComplianceCaseClosureReportReviewManager::rules();
    }
}
