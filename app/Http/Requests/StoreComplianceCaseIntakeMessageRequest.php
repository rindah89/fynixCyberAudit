<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseIntakeCorrespondenceManager;
use App\Enums\ComplianceCaseIntakeAudience;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreComplianceCaseIntakeMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! Enterprise::enabled('compliance_cases')) {
            return false;
        }

        $intake = $this->route('intake');
        if ($this->user()->can('Manage Compliance Cases')) {
            return true;
        }

        return $intake && $intake->submitted_by === $this->user()->id && ! $this->user()->trashed() && $this->input('audience') === ComplianceCaseIntakeAudience::Reporter->value;
    }

    public function rules(): array
    {
        return ComplianceCaseIntakeCorrespondenceManager::rules();
    }
}
