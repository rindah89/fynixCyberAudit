<?php

namespace App\Http\Requests;

use App\Models\ComplianceCase;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class StoreComplianceCaseReopenProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $case = $this->route('complianceCase') ?? $this->route('case');

        return Enterprise::enabled('compliance_cases') && $case instanceof ComplianceCase
            && $this->user()?->can('Manage Compliance Cases') === true && $this->user()?->can('view', $case) === true;
    }

    public function rules(): array
    {
        return [
            'summary' => 'required|string|max:30000',
            'id' => 'prohibited', 'version' => 'prohibited', 'fingerprint' => 'prohibited',
            'proposed_by' => 'prohibited', 'proposed_at' => 'prohibited', 'proposer_snapshot' => 'prohibited',
            'case_snapshot' => 'prohibited',
        ];
    }
}
