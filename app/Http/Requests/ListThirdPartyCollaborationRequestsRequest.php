<?php

namespace App\Http\Requests;

use App\Models\ThirdPartyEngagement;
use Illuminate\Foundation\Http\FormRequest;

class ListThirdPartyCollaborationRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $engagement = $this->route('engagement');
        $actor = $this->user();

        return $engagement instanceof ThirdPartyEngagement && $actor
            && ($actor->isSuperAdmin() || $actor->can('Manage Third Party Risk') || $actor->can('Read Vendors')
                || in_array($actor->id, [$engagement->business_owner_id, $engagement->vendor->vendor_manager_id], true));
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
