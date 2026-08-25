<?php

namespace App\Http\Requests;

use App\Models\ComplianceCaseIntake;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ListMyComplianceCaseIntakesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Enterprise::enabled('compliance_cases') && $this->user()?->can('create', ComplianceCaseIntake::class) === true;
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
