<?php

namespace App\Http\Requests;

use App\Enums\TechnologyExposureType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTechnologyExposureAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage Risk Portfolio') ?? false;
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'integer', 'exists:assets,id'], 'exposure_type' => ['required', Rule::enum(TechnologyExposureType::class)],
            'title' => ['required', 'string', 'max:255'], 'threat_scenario' => ['required', 'string', 'max:30000'],
            'vulnerability_reference' => ['nullable', 'string', 'max:255'], 'vulnerability_description' => ['required', 'string', 'max:30000'],
            'source_reference' => ['nullable', 'string', 'max:255'], 'inherent_likelihood' => ['required', 'integer', 'between:1,5'],
            'inherent_impact' => ['required', 'integer', 'between:1,5'], 'residual_likelihood' => ['required', 'integer', 'between:1,5'],
            'residual_impact' => ['required', 'integer', 'between:1,5'], 'recommended_response' => ['required', 'string', 'max:30000'],
            'review_due_at' => ['required', 'date', 'after_or_equal:today'],
        ];
    }
}
