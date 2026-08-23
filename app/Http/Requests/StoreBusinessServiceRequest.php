<?php

namespace App\Http\Requests;

use App\Enums\ResilienceCriticality;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusinessServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('resilience') && $this->user()?->can('Manage Resilience') === true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:255|unique:business_services,code', 'name' => 'required|string|max:255',
            'owner_id' => 'required|exists:users,id', 'description' => 'nullable|string',
            'criticality' => ['required', Rule::enum(ResilienceCriticality::class)], 'status' => 'sometimes|in:active,inactive',
        ];
    }
}
