<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseMilestoneManager;
use App\Models\ComplianceCase;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreComplianceCaseMilestoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('complianceCase') ?? $this->route('case');

        return Enterprise::enabled('compliance_cases') && $case instanceof ComplianceCase
            && $this->user()?->can('Manage Compliance Cases') === true && $this->user()?->can('view', $case) === true;
    }

    public function rules(): array
    {
        return ComplianceCaseMilestoneManager::defineRules();
    }
}
