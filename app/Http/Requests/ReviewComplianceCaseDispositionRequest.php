<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseRetentionManager;
use App\Models\ComplianceCaseRetentionClassification;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ReviewComplianceCaseDispositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $classification = $this->route('classification');

        return Enterprise::enabled('compliance_cases') && $classification instanceof ComplianceCaseRetentionClassification
            && $this->user()?->can('Manage Compliance Cases') === true
            && (int) $this->user()->id !== (int) $classification->classified_by;
    }

    public function rules(): array
    {
        return ComplianceCaseRetentionManager::reviewRules();
    }
}
