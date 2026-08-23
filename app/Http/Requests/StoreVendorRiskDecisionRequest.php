<?php

namespace App\Http\Requests;

use App\Enums\ThirdPartyRiskDecisionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVendorRiskDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage Third Party Risk') ?? false;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(ThirdPartyRiskDecisionType::class)], 'rationale' => 'required|string|max:30000',
            'conditions' => 'required_if:decision,conditionally_approved|nullable|string|max:30000',
            'expires_at' => 'required_if:decision,approved,conditionally_approved|nullable|date|after:today',
            'next_review_at' => 'required_if:decision,approved,conditionally_approved|nullable|date|after:today|before_or_equal:expires_at',
            'assessment_version' => 'prohibited', 'residual_score' => 'prohibited', 'risk_ids' => 'prohibited', 'governance_fingerprint' => 'prohibited',
        ];
    }
}
