<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseInvestigationPlanManager;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ReviewComplianceCaseInvestigationPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('compliance_cases') && $this->user()->can('Manage Compliance Cases');
    }

    public function rules(): array
    {
        return ComplianceCaseInvestigationPlanManager::reviewRules();
    }
}
