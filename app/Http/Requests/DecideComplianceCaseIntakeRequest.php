<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseIntakeManager;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class DecideComplianceCaseIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('compliance_cases') && $this->user()?->can('Manage Compliance Cases') === true;
    }

    public function rules(): array
    {
        return ComplianceCaseIntakeManager::decisionRules();
    }
}
