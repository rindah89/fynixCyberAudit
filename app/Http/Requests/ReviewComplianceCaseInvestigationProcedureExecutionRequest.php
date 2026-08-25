<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseInvestigationProcedureExecutionManager;
use App\Models\ComplianceCaseInvestigationProcedureExecution;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ReviewComplianceCaseInvestigationProcedureExecutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $execution = $this->route('execution');

        return Enterprise::enabled('compliance_cases') && $execution instanceof ComplianceCaseInvestigationProcedureExecution
            && $this->user()->can('Manage Compliance Cases');
    }

    public function rules(): array
    {
        return ComplianceCaseInvestigationProcedureExecutionManager::reviewRules();
    }
}
