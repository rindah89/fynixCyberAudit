<?php

namespace App\Http\Requests;

use App\Models\Policy;
use App\PolicyCompliance\PolicyRevisionManager;
use Illuminate\Foundation\Http\FormRequest;

class SubmitPolicyRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $policy = $this->route('policy');

        return $policy instanceof Policy
            && ($this->user()?->can('Update Policies') || $policy->owner_id === $this->user()?->id);
    }

    public function rules(): array
    {
        return PolicyRevisionManager::submissionRules() + [
            'policy_id' => ['prohibited'], 'version' => ['prohibited'], 'status' => ['prohibited'],
            'policy_snapshot' => ['prohibited'], 'submitted_by' => ['prohibited'],
            'submitted_at' => ['prohibited'], 'fingerprint' => ['prohibited'],
        ];
    }
}
