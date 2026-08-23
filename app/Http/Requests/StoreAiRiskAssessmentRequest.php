<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiRiskAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage AI Governance') === true;
    }

    public function rules(): array
    {
        return [
            'version' => 'prohibited', 'inherent_score' => 'prohibited', 'residual_score' => 'prohibited', 'risk_tier' => 'prohibited',
            'likelihood' => 'required|integer|between:1,5', 'impact' => 'required|integer|between:1,5',
            'residual_likelihood' => 'required|integer|between:1,5', 'residual_impact' => 'required|integer|between:1,5',
            'risk_categories' => 'required|array|min:1|max:20',
            'risk_categories.*' => ['required', 'string', 'distinct', Rule::in(['fairness', 'privacy', 'security', 'safety', 'transparency', 'accountability', 'human_rights', 'regulatory'])],
            'assessment_summary' => 'required|string|max:30000', 'mitigation_summary' => 'required|string|max:30000',
        ];
    }
}
