<?php

namespace App\Http\Requests;

use App\Enums\AiDecisionImpact;
use App\Enums\AiLifecycleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiSystemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Manage AI Governance') === true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:255|unique:ai_systems,code', 'name' => 'required|string|max:255',
            'owner_id' => 'required|exists:users,id', 'vendor_id' => 'nullable|exists:vendors,id', 'application_id' => 'nullable|exists:applications,id',
            'provider_name' => 'required|string|max:255', 'model_name' => 'required|string|max:255', 'deployment_type' => 'required|string|max:255',
            'lifecycle_status' => ['required', Rule::enum(AiLifecycleStatus::class)], 'criticality' => ['required', Rule::enum(AiDecisionImpact::class)],
            'intended_purpose' => 'required|string|max:20000', 'prohibited_uses' => 'nullable|string|max:20000',
            'human_oversight' => 'required|string|max:20000', 'data_categories' => 'sometimes|array|max:50',
            'data_categories.*' => 'string|max:255|distinct', 'next_review_at' => 'required|date|after_or_equal:today',
        ];
    }
}
