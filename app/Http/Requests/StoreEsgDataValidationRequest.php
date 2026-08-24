<?php

namespace App\Http\Requests;

use App\Esg\EsgDisclosureManager;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreEsgDataValidationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return Enterprise::enabled('esg_management') && ($actor?->can('Validate ESG Data') === true || $actor?->can('Manage ESG') === true);
    }

    public function rules(): array
    {
        return EsgDisclosureManager::validationRules();
    }
}
