<?php

namespace App\Http\Requests;

use App\Models\ComplianceCase;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class ListComplianceCaseInvestigationReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('case');

        return Enterprise::enabled('compliance_cases') && $case instanceof ComplianceCase && $this->user()->can('view', $case);
    }

    public function rules(): array
    {
        return ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100'];
    }
}
