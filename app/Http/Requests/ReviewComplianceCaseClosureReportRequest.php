<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseClosureReportReviewManager;
use App\Models\ComplianceCaseClosureReport;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ReviewComplianceCaseClosureReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $report = $this->route('report');

        return Enterprise::enabled('compliance_cases') && $report instanceof ComplianceCaseClosureReport
            && $this->user()?->can('update', $report) === true;
    }

    public function rules(): array
    {
        return ComplianceCaseClosureReportReviewManager::rules();
    }
}
