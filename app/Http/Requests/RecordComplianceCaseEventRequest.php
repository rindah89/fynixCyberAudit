<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseManager;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class RecordComplianceCaseEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('compliance_cases') && $this->user()?->can('update', $this->route('complianceCase')) === true;
    }

    public function rules(): array
    {
        return ComplianceCaseManager::eventRules();
    }
}
