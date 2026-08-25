<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseLegalHoldManager;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ReleaseComplianceCaseLegalHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('compliance_cases')
            && $this->user()?->can('Manage Compliance Cases') === true
            && $this->user()?->can('view', $this->route('complianceCase')) === true;
    }

    public function rules(): array
    {
        return ComplianceCaseLegalHoldManager::releaseRules();
    }
}
