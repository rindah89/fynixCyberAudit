<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseInvestigationProcedureExecutionManager;
use App\Models\ComplianceCase;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreComplianceCaseInvestigationProcedureExecutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('case');

        return Enterprise::enabled('compliance_cases') && $case instanceof ComplianceCase && $this->user()->can('update', $case);
    }

    public function rules(): array
    {
        return ComplianceCaseInvestigationProcedureExecutionManager::rules();
    }
}
