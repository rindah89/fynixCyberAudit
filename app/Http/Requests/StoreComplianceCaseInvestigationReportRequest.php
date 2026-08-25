<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseInvestigationReportManager;
use App\Models\ComplianceCase;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreComplianceCaseInvestigationReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('case');

        return Enterprise::enabled('compliance_cases') && $case instanceof ComplianceCase && $this->user()->can('update', $case);
    }

    public function rules(): array
    {
        return ComplianceCaseInvestigationReportManager::submitRules();
    }
}
