<?php

namespace App\Http\Requests;

use App\Enums\RiskDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MapVendorRiskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage Third Party Risk') ?? false;
    }

    public function rules(): array
    {
        return ['risk_id' => ['required', Rule::exists('risks', 'id')->where('domain', RiskDomain::ThirdParty->value)]];
    }
}
