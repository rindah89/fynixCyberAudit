<?php

namespace App\Http\Requests;

use App\Models\ThirdPartyEngagement;
use Illuminate\Foundation\Http\FormRequest;

class ShowThirdPartyEngagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = $this->route('engagement');

        return $record instanceof ThirdPartyEngagement && ($this->user()?->can('Manage Third Party Risk') || $this->user()?->can('Read Vendors') || $record->vendor->vendor_manager_id === $this->user()?->id);
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
