<?php

namespace App\Http\Requests;

use App\Enums\AiDecisionImpact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiUseCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage AI Governance') === true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255', 'owner_id' => 'required|exists:users,id', 'purpose' => 'required|string|max:20000',
            'decision_impact' => ['required', Rule::enum(AiDecisionImpact::class)], 'affected_population' => 'required|string|max:10000',
            'uses_personal_data' => 'required|boolean', 'uses_sensitive_data' => 'required|boolean', 'automated_decision' => 'required|boolean',
        ];
    }
}
