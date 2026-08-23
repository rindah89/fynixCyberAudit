<?php

namespace App\Http\Requests;

use App\Enums\AiGovernanceDecisionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiGovernanceDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage AI Governance') === true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(AiGovernanceDecisionType::class)], 'rationale' => 'required|string|max:30000',
            'conditions' => 'nullable|string|max:30000', 'expires_at' => 'required_if:decision,approved|nullable|date|after:today',
            'next_monitoring_at' => 'required_if:decision,approved|nullable|date|after:today',
            'assessment_version' => 'prohibited', 'residual_score' => 'prohibited', 'controls_count' => 'prohibited', 'risks_count' => 'prohibited',
        ];
    }
}
