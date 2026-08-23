<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVendorRiskAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage Third Party Risk') ?? false;
    }

    public function rules(): array
    {
        return [
            'version' => 'prohibited', 'inherent_score' => 'prohibited', 'residual_score' => 'prohibited', 'survey_score_snapshot' => 'prohibited',
            'survey_id' => 'nullable|exists:surveys,id', 'likelihood' => 'required|integer|between:1,5', 'impact' => 'required|integer|between:1,5',
            'residual_likelihood' => 'required|integer|between:1,5', 'residual_impact' => 'required|integer|between:1,5',
            'risk_categories' => 'required|array|min:1|max:20',
            'risk_categories.*' => ['required', 'string', 'distinct', Rule::in(['cybersecurity', 'privacy', 'operational', 'financial', 'concentration', 'geographic', 'compliance', 'reputational', 'subcontractor'])],
            'assessment_summary' => 'required|string|max:30000', 'treatment_summary' => 'required|string|max:30000',
        ];
    }
}
