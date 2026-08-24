<?php

namespace App\Http\Requests;

use App\Models\SystemAuthorizationPackage;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ListSystemAuthorizationPackagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('system_authorization') && $this->user()?->can('viewAny', SystemAuthorizationPackage::class) === true;
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
