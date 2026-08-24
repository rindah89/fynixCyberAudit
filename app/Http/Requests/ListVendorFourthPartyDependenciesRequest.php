<?php

namespace App\Http\Requests;

use App\Models\Vendor;
use Illuminate\Foundation\Http\FormRequest;

class ListVendorFourthPartyDependenciesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $vendor = $this->route('vendor');

        return $user && $vendor instanceof Vendor && ($user->can('Manage Third Party Risk') || $user->can('Read Vendors') || $vendor->vendor_manager_id === $user->id);
    }

    public function rules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']];
    }
}
