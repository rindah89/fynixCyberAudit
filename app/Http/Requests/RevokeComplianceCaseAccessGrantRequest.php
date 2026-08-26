<?php

namespace App\Http\Requests;

use App\ComplianceCases\ComplianceCaseConflictManager;
use App\Models\ComplianceCaseAccessGrant;
use App\Support\Enterprise;
use Illuminate\Foundation\Http\FormRequest;

class RevokeComplianceCaseAccessGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $grant = $this->route('grant');

        return Enterprise::enabled('compliance_cases') && $grant instanceof ComplianceCaseAccessGrant
            && $this->user()?->can('Manage Compliance Cases') === true
            && $this->user()?->can('view', $grant->complianceCase) === true
            && ! app(ComplianceCaseConflictManager::class)->isRecused((int) $this->user()->id, (int) $grant->compliance_case_id);
    }

    public function rules(): array
    {
        return [
            'summary' => 'required|string|max:30000',
            'id' => 'prohibited', 'fingerprint' => 'prohibited', 'revoked_by' => 'prohibited',
            'revoked_at' => 'prohibited', 'revoker_snapshot' => 'prohibited', 'grant_snapshot' => 'prohibited',
        ];
    }
}
