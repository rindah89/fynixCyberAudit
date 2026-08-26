<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseConflictManager;
use App\ComplianceCases\ComplianceCaseLegalHoldManager;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ReleaseComplianceCaseLegalHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('complianceCase');

        return Enterprise::enabled('compliance_cases')
            && $this->user()?->can('Manage Compliance Cases') === true
            && $this->user()?->can('view', $case) === true
            && $case !== null
            && ! app(ComplianceCaseConflictManager::class)->isRecused((int) $this->user()->id, (int) $case->id);
    }

    public function rules(): array
    {
        return ComplianceCaseLegalHoldManager::releaseRules();
    }
}
