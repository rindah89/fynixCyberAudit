<?php

namespace App\Http\Requests;

use App\Models\Policy;
use App\PolicyCompliance\PolicyAcknowledgementManager;
use Illuminate\Foundation\Http\FormRequest;

class LaunchPolicyAcknowledgementCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        $policy = $this->route('policy');

        return $policy instanceof Policy && ($this->user()?->can('Update Policies') || $policy->owner_id === $this->user()?->id);
    }

    public function rules(): array
    {
        return PolicyAcknowledgementManager::launchRules() + [
            'policy_id' => ['prohibited'], 'version' => ['prohibited'], 'launched_by' => ['prohibited'],
            'launched_at' => ['prohibited'], 'policy_snapshot' => ['prohibited'], 'policy_fingerprint' => ['prohibited'],
            'closed_by' => ['prohibited'], 'closed_at' => ['prohibited'],
        ];
    }
}
