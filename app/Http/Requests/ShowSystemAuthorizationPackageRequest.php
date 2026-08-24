<?php

namespace App\Http\Requests;

use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ShowSystemAuthorizationPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('system_authorization') && $this->user()?->can('view', $this->route('package')) === true;
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
