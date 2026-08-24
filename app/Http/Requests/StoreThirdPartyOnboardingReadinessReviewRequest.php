<?php

namespace App\Http\Requests;

use App\Models\ThirdPartyEngagement;
use App\ThirdPartyRisk\ThirdPartyEngagementOnboardingManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreThirdPartyOnboardingReadinessReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('engagement') instanceof ThirdPartyEngagement && ($this->user()?->isSuperAdmin() || $this->user()?->can('Manage Third Party Risk'));
    }

    public function rules(): array
    {
        return ThirdPartyEngagementOnboardingManager::reviewRules();
    }
}
