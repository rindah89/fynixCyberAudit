<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseInvestigationPlanManager;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreComplianceCaseInvestigationPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('case');

        return Enterprise::enabled('compliance_cases') && ($this->user()->can('Manage Compliance Cases') || ($this->user()->can('Investigate Compliance Cases') && $case?->assigned_to === $this->user()->id));
    }

    public function rules(): array
    {
        return ComplianceCaseInvestigationPlanManager::planRules();
    }
}
