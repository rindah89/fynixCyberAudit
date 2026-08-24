<?php

namespace App\Http\Requests;

use App\Models\ThirdPartyEngagementOffboardingRequirement;
use App\ThirdPartyRisk\ThirdPartyEngagementOffboardingManager;
use Illuminate\Foundation\Http\FormRequest;

class CompleteThirdPartyOffboardingRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $requirement = $this->route('requirement');

        return $this->user()?->isSuperAdmin() || $this->user()?->can('Manage Third Party Risk') || ($requirement instanceof ThirdPartyEngagementOffboardingRequirement && $requirement->owner_id === $this->user()?->id);
    }

    public function rules(): array
    {
        return ThirdPartyEngagementOffboardingManager::completionRules();
    }
}
