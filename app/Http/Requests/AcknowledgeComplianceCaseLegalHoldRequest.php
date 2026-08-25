<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseLegalHoldManager;
use App\Models\ComplianceCaseLegalHold;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class AcknowledgeComplianceCaseLegalHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        $hold = $this->route('legalHold');

        return Enterprise::enabled('compliance_cases') && $hold instanceof ComplianceCaseLegalHold
            && $this->user()?->can('acknowledge', $hold) === true;
    }

    public function rules(): array
    {
        return ComplianceCaseLegalHoldManager::acknowledgementRules();
    }
}
