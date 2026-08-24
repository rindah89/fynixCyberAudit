<?php

namespace App\Http\Requests;

use App\Models\RiskIndicator;
use Illuminate\Foundation\Http\FormRequest;

class StoreRiskIndicatorObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $indicator = $this->route('indicator');

        return $this->user() && $indicator instanceof RiskIndicator && ($this->user()->can('Manage Risk Portfolio') || $indicator->owner_id === $this->user()->id || $indicator->risk()->whereHas('governanceProfile', fn ($query) => $query->where('owner_id', $this->user()->id))->exists());
    }

    public function rules(): array
    {
        return ['observed_value' => ['required', 'regex:/^-?\d{1,15}(?:\.\d{1,6})?$/'], 'observed_at' => ['nullable', 'date', 'before_or_equal:now'], 'notes' => ['nullable', 'string', 'max:30000'], 'source_reference' => ['nullable', 'string', 'max:255']];
    }
}
