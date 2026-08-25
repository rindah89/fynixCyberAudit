<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseIntakeManager;
use App\Models\ComplianceCaseIntake;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class DecideComplianceCaseIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $intake = $this->route('intake');

        return Enterprise::enabled('compliance_cases') && $intake instanceof ComplianceCaseIntake
            && $this->user()?->can('update', $intake) === true;
    }

    public function rules(): array
    {
        return ComplianceCaseIntakeManager::decisionRules();
    }
}
