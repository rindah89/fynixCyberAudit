<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseIntakeCorrespondenceManager;
use App\Enums\ComplianceCaseIntakeAudience;
use App\Models\ComplianceCaseIntakeMessage;
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
        if (! $intake || ! $this->user()?->can('view', $intake) || ! $this->user()->can('create', ComplianceCaseIntakeMessage::class)) {
            return false;
        }
        if ($this->user()->can('Manage Compliance Cases')) {
            return true;
        }

        return $intake->submitted_by === $this->user()->id && $this->input('audience') === ComplianceCaseIntakeAudience::Reporter->value;
    }

    public function rules(): array
    {
        return ComplianceCaseIntakeCorrespondenceManager::rules();
    }
}
