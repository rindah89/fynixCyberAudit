<?php

namespace App\Http\Requests;

use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ListComplianceCaseInvestigationPlansRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('compliance_cases') && $this->user()->can('view', $this->route('case'));
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
