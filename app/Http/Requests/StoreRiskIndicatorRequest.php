<?php

namespace App\Http\Requests;

use App\Enums\RiskIndicatorDirection;
use App\Enums\RiskIndicatorFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRiskIndicatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage Risk Portfolio') ?? false;
    }

    public function rules(): array
    {
        return [
            'owner_id' => ['required', 'integer', 'exists:users,id'], 'code' => ['required', 'string', 'max:100', Rule::unique('risk_indicators')->where('risk_id', $this->route('risk')->id)],
            'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:30000'],
            'unit' => ['required', 'string', 'max:50'], 'direction' => ['required', Rule::enum(RiskIndicatorDirection::class)],
            'warning_threshold' => ['required', 'regex:/^-?\d{1,15}(?:\.\d{1,6})?$/'],
            'critical_threshold' => ['required', 'regex:/^-?\d{1,15}(?:\.\d{1,6})?$/'],
            'frequency' => ['required', Rule::enum(RiskIndicatorFrequency::class)], 'next_due_at' => ['required', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
