<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseManager;
use App\Models\ComplianceCase;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreComplianceCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('compliance_cases') && $this->user()?->can('create', ComplianceCase::class) === true;
    }

    public function rules(): array
    {
        return ComplianceCaseManager::openRules();
    }
}
