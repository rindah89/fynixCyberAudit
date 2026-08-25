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
        return Enterprise::enabled('compliance_cases')
            && $this->route('report') instanceof ComplianceCaseClosureReport
            && $this->user()->can('Manage Compliance Cases');
    }

    public function rules(): array
    {
        return ComplianceCaseClosureReportReviewManager::rules();
    }
}
