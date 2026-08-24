<?php

namespace App\Http\Requests;

use App\Models\ThirdPartyEngagementMonitoringIndicator;
use App\ThirdPartyRisk\ThirdPartyEngagementMonitoringManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreThirdPartyEngagementMonitoringObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $indicator = $this->route('indicator');

        return $indicator instanceof ThirdPartyEngagementMonitoringIndicator && ($this->user()?->isSuperAdmin() || $this->user()?->can('Manage Third Party Risk') || $this->user()?->id === $indicator->owner_id || $this->user()?->id === $indicator->engagement->business_owner_id);
    }

    public function rules(): array
    {
        return ThirdPartyEngagementMonitoringManager::observationRules();
    }
}
