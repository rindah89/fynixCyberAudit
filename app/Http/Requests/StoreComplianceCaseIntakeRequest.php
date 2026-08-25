<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseIntakeManager;
use App\Models\ComplianceCaseIntake;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreComplianceCaseIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('compliance_cases') && $this->user()?->can('create', ComplianceCaseIntake::class) === true;
    }

    public function rules(): array
    {
        return ComplianceCaseIntakeManager::submissionRules();
    }
}
