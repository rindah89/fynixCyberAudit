<?php

namespace App\Http\Requests;

use App\Enums\EnterpriseScenarioProbability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnterpriseRiskScenarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage Risk Portfolio') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'], 'narrative' => ['required', 'string', 'max:30000'],
            'horizon_months' => ['required', 'integer', 'between:1,120'],
            'probability_band' => ['required', Rule::enum(EnterpriseScenarioProbability::class)],
            'adjustments' => ['required', 'array', 'min:1', 'max:10000'],
            'adjustments.*.risk_id' => ['required', 'integer', 'distinct', 'exists:risks,id'],
            'adjustments.*.likelihood_shift' => ['required', 'integer', 'between:-4,4'],
            'adjustments.*.impact_shift' => ['required', 'integer', 'between:-4,4'],
            'adjustments.*.rationale' => ['nullable', 'string', 'max:30000'],
        ];
    }
}
