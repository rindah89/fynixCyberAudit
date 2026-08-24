<?php

namespace App\Http\Requests;

use App\Models\ThirdPartyEngagementOnboardingRequirement;
use App\ThirdPartyRisk\ThirdPartyEngagementOnboardingManager;
use Illuminate\Foundation\Http\FormRequest;

class CompleteThirdPartyOnboardingRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $r = $this->route('requirement');

        return $r instanceof ThirdPartyEngagementOnboardingRequirement && ($this->user()?->isSuperAdmin() || $this->user()?->can('Manage Third Party Risk') || $this->user()?->id === $r->owner_id);
    }

    public function rules(): array
    {
        return ThirdPartyEngagementOnboardingManager::completionRules();
    }
}
