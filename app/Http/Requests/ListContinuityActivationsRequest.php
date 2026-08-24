<?php

namespace App\Http\Requests;

use App\Models\BusinessService;
use Illuminate\Foundation\Http\FormRequest;

class ListContinuityActivationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $service = $this->route('service');

        return $service instanceof BusinessService && $this->user()?->can('view', $service) === true;
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
