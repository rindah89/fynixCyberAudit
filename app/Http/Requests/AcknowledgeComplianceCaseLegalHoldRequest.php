<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseLegalHoldManager;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class AcknowledgeComplianceCaseLegalHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('compliance_cases') && $this->user() !== null;
    }

    public function rules(): array
    {
        return ComplianceCaseLegalHoldManager::acknowledgementRules();
    }
}
