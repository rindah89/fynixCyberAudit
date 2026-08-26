<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseAccessGrantManager;
use App\Models\ComplianceCase;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ShowComplianceCasePortfolioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('compliance_cases') && $this->user() !== null
            && ($this->user()->can('viewAny', ComplianceCase::class)
                || ComplianceCaseAccessGrantManager::granteeHasAnyActiveGrant($this->user()));
    }

    public function rules(): array
    {
        return [
            'opened_from' => 'sometimes|date_format:Y-m-d',
            'opened_to' => 'sometimes|date_format:Y-m-d|after_or_equal:opened_from',
            'format' => 'sometimes|in:json,csv',
        ];
    }
}
