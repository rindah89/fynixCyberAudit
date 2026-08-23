<?php

namespace App\Http\Requests;

use App\Enums\RiskGovernanceDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRiskGovernanceReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage Risk Portfolio') ?? false;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(RiskGovernanceDecision::class)], 'summary' => 'required|string|max:30000',
            'evidence_reference' => 'nullable|string|max:255', 'next_review_at' => 'required|date|after:today',
            'domain_snapshot' => 'prohibited', 'inherent_score_snapshot' => 'prohibited', 'residual_score_snapshot' => 'prohibited',
            'appetite_threshold_snapshot' => 'prohibited', 'asset_ids_snapshot' => 'prohibited', 'implementation_ids_snapshot' => 'prohibited',
            'business_service_id_snapshot' => 'prohibited', 'governance_fingerprint' => 'prohibited',
        ];
    }
}
