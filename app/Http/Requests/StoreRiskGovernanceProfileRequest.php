<?php

namespace App\Http\Requests;

use App\Enums\RiskReviewFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRiskGovernanceProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage Risk Portfolio') ?? false;
    }

    public function rules(): array
    {
        return [
            'owner_id' => 'required|exists:users,id', 'appetite_threshold' => 'required|integer|between:1,25',
            'review_frequency' => ['required', Rule::enum(RiskReviewFrequency::class)],
            'strategic_objective' => 'nullable|string|max:30000', 'business_service_id' => 'nullable|exists:business_services,id',
            'context_notes' => 'nullable|string|max:30000', 'next_review_at' => 'required|date|after:today',
        ];
    }
}
