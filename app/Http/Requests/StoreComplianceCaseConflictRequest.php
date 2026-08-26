<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseConflictManager;
use App\Models\ComplianceCase;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreComplianceCaseConflictRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('complianceCase') ?? $this->route('case');

        return Enterprise::enabled('compliance_cases') && $case instanceof ComplianceCase
            && $this->user()?->can('update', $case) === true;
    }

    public function rules(): array
    {
        return ComplianceCaseConflictManager::declarationRules();
    }
}
