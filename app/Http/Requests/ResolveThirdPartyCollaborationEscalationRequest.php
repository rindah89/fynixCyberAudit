<?php

namespace App\Http\Requests;

use App\Models\ThirdPartyEngagementCollaborationEscalation;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationResolutionManager;
use Illuminate\Foundation\Http\FormRequest;

class ResolveThirdPartyCollaborationEscalationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $escalation = $this->route('escalation');
        $user = $this->user();

        return $escalation instanceof ThirdPartyEngagementCollaborationEscalation && $user
            && ($user->isSuperAdmin() || $user->can('Manage Third Party Risk')
                || in_array($user->id, [$escalation->engagement->business_owner_id, $escalation->engagement->vendor->vendor_manager_id], true));
    }

    public function rules(): array
    {
        return ThirdPartyEngagementCollaborationResolutionManager::resolutionRules();
    }
}
