<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseConflictManager;
use App\Models\ComplianceCaseConflictDeclaration;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class DecideComplianceCaseConflictRequest extends FormRequest
{
    public function authorize(): bool
    {
        $declaration = $this->route('declaration') ?? $this->route('conflict');

        return Enterprise::enabled('compliance_cases') && $declaration instanceof ComplianceCaseConflictDeclaration
            && $this->user()?->can('Manage Compliance Cases') === true
            && $this->user()?->can('view', $declaration->complianceCase) === true
            && (int) $this->user()->id !== (int) $declaration->subject_user_id
            && (int) $this->user()->id !== (int) $declaration->declared_by
            && ! app(ComplianceCaseConflictManager::class)->isRecused((int) $this->user()->id, (int) $declaration->compliance_case_id);
    }

    public function rules(): array
    {
        return ComplianceCaseConflictManager::decisionRules();
    }
}
