<?php

namespace App\Http\Requests;

use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementMonitoringIndicator;
use Illuminate\Foundation\Http\FormRequest;

class ListThirdPartyEngagementMonitoringRequest extends FormRequest
{
    public function authorize(): bool
    {
        $engagement = $this->route('engagement') ?? $this->route('indicator')?->engagement;

        return $engagement instanceof ThirdPartyEngagement && ($this->user()?->can('Manage Third Party Risk') || $this->user()?->can('Read Vendors') || $engagement->vendor->vendor_manager_id === $this->user()?->id || $engagement->business_owner_id === $this->user()?->id || ($this->route('indicator') instanceof ThirdPartyEngagementMonitoringIndicator && $this->route('indicator')->owner_id === $this->user()?->id));
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
