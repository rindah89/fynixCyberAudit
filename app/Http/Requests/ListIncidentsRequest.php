<?php

namespace App\Http\Requests;

use App\Models\Incident;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ListIncidentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('incidents') && $this->user()?->can('viewAny', Incident::class) === true;
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
