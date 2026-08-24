<?php

namespace App\Http\Requests;

use App\Models\RiskIndicator;
use Illuminate\Foundation\Http\FormRequest;

class ListRiskIndicatorObservationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $indicator = $this->route('indicator');

        return $this->user() && $indicator instanceof RiskIndicator && ($this->user()->can('Manage Risk Portfolio') || $this->user()->can('Read Risks') || $indicator->risk()->whereHas('governanceProfile', fn ($query) => $query->where('owner_id', $this->user()->id))->exists());
    }

    public function rules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
