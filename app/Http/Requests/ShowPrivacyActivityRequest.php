<?php

namespace App\Http\Requests;

use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ShowPrivacyActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('privacy_management') && $this->user()?->can('view', $this->route('activity')) === true;
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
