<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseInvestigationReportManager;
use App\Models\ComplianceCaseInvestigationReport;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ReviewComplianceCaseInvestigationReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('compliance_cases') && $this->route('report') instanceof ComplianceCaseInvestigationReport
            && $this->user()->can('Manage Compliance Cases');
    }

    public function rules(): array
    {
        return ComplianceCaseInvestigationReportManager::reviewRules();
    }
}
