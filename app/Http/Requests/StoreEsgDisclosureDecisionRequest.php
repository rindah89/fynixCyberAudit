<?php

namespace App\Http\Requests;

use App\Esg\EsgDisclosureManager;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreEsgDisclosureDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('esg_management') && $this->user()?->can('decide', $this->route('disclosure')) === true;
    }

    public function rules(): array
    {
        return EsgDisclosureManager::decisionRules();
    }
}
