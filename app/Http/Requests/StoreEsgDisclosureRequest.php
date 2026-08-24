<?php

namespace App\Http\Requests;

use App\Esg\EsgDisclosureManager;
use App\Models\EsgDisclosure;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreEsgDisclosureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('esg_management') && $this->user()?->can('create', EsgDisclosure::class) === true;
    }

    public function rules(): array
    {
        return EsgDisclosureManager::disclosureRules();
    }
}
