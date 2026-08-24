<?php

namespace App\Http\Requests;

use App\Models\ThirdPartyEngagementCollaborationEscalation;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationIssueManager;
use Illuminate\Foundation\Http\FormRequest;

class OpenThirdPartyCollaborationEscalationIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('escalation') instanceof ThirdPartyEngagementCollaborationEscalation
            && ($this->user()?->isSuperAdmin() || $this->user()?->can('Manage Third Party Risk'));
    }

    public function rules(): array
    {
        return ThirdPartyEngagementCollaborationIssueManager::rules();
    }
}
