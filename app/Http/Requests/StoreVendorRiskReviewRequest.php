<?php

namespace App\Http\Requests;

use App\Enums\ThirdPartyRiskReviewOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVendorRiskReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage Third Party Risk') ?? false;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::enum(ThirdPartyRiskReviewOutcome::class)], 'summary' => 'required|string|max:30000',
            'evidence_reference' => 'nullable|string|max:255', 'next_review_at' => 'required|date|after:today',
        ];
    }
}
