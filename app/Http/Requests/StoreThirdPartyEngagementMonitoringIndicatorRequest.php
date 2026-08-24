<?php

namespace App\Http\Requests;

use App\Models\ThirdPartyEngagement;
use App\ThirdPartyRisk\ThirdPartyEngagementMonitoringManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreThirdPartyEngagementMonitoringIndicatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('engagement') instanceof ThirdPartyEngagement && ($this->user()?->isSuperAdmin() || $this->user()?->can('Manage Third Party Risk'));
    }

    public function rules(): array
    {
        return ThirdPartyEngagementMonitoringManager::definitionRules();
    }
}
