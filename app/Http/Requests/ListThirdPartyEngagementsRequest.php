<?php

namespace App\Http\Requests;

use App\Models\Vendor;
use Illuminate\Foundation\Http\FormRequest;

class ListThirdPartyEngagementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $vendor = $this->route('vendor');

        return $vendor instanceof Vendor && ($this->user()?->can('Manage Third Party Risk') || $this->user()?->can('Read Vendors') || $vendor->vendor_manager_id === $this->user()?->id);
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
