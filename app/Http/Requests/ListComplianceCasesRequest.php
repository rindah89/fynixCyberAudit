<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseAccessGrantManager;
use App\Models\ComplianceCase;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ListComplianceCasesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('compliance_cases') && $this->user() !== null
            && ($this->user()->can('viewAny', ComplianceCase::class)
                || ComplianceCaseAccessGrantManager::granteeHasAnyActiveGrant($this->user()));
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
