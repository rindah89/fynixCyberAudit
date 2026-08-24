<?php

namespace App\Http\Requests;

use App\Models\EsgDisclosure;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ListEsgDisclosuresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('esg_management') && $this->user()?->can('viewAny', EsgDisclosure::class) === true;
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
