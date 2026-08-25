<?php

namespace App\Http\Requests;

use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ListMyComplianceCaseLegalHoldsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('compliance_cases') && $this->user() !== null;
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
