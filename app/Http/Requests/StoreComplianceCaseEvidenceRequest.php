<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseEvidenceManager;
use App\Models\ComplianceCase;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreComplianceCaseEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('complianceCase');
        $actor = $this->user();

        return Enterprise::enabled('compliance_cases') && $case instanceof ComplianceCase && $actor
            && ($actor->can('Manage Compliance Cases')
                || ($actor->can('Investigate Compliance Cases') && $case->assigned_to === $actor->id));
    }

    public function rules(): array
    {
        return ComplianceCaseEvidenceManager::rules();
    }
}
