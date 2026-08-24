<?php

namespace App\Http\Requests;

use App\Models\Policy;
use App\PolicyCompliance\PolicyExceptionGovernanceManager;
use Illuminate\Foundation\Http\FormRequest;

class SubmitPolicyExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $policy = $this->route('policy');

        return $policy instanceof Policy && ($policy->owner_id === $this->user()?->id
            || $this->user()?->can('Read Policies') || $this->user()?->can('Update Policies'));
    }

    public function rules(): array
    {
        return PolicyExceptionGovernanceManager::requestRules() + [
            'policy_id' => ['prohibited'], 'status' => ['prohibited'], 'requested_by' => ['prohibited'],
            'requested_date' => ['prohibited'], 'submitted_at' => ['prohibited'], 'approved_by' => ['prohibited'],
            'governance_snapshot' => ['prohibited'], 'governance_fingerprint' => ['prohibited'],
            'created_by' => ['prohibited'], 'updated_by' => ['prohibited'],
        ];
    }
}
