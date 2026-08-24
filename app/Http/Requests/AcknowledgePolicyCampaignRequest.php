<?php

namespace App\Http\Requests;

use App\Models\PolicyAcknowledgementAssignment;
use App\PolicyCompliance\PolicyAcknowledgementManager;
use Illuminate\Foundation\Http\FormRequest;

class AcknowledgePolicyCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof PolicyAcknowledgementAssignment && $assignment->user_id === $this->user()?->id;
    }

    public function rules(): array
    {
        return PolicyAcknowledgementManager::acknowledgementRules() + [
            'statement' => ['prohibited'], 'acknowledged_by' => ['prohibited'], 'acknowledged_at' => ['prohibited'],
            'campaign_snapshot' => ['prohibited'], 'policy_snapshot' => ['prohibited'], 'policy_fingerprint' => ['prohibited'],
        ];
    }
}
