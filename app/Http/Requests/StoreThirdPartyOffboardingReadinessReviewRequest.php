<?php

namespace App\Http\Requests;

use App\ThirdPartyRisk\ThirdPartyEngagementOffboardingManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreThirdPartyOffboardingReadinessReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() || $this->user()?->can('Manage Third Party Risk');
    }

    public function rules(): array
    {
        return ThirdPartyEngagementOffboardingManager::reviewRules();
    }
}
