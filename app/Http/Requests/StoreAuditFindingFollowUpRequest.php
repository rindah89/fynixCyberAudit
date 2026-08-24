<?php

namespace App\Http\Requests;

use App\Models\AuditFindingRemediation;
use App\Services\AuditFindingRemediationManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreAuditFindingFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('remediation') instanceof AuditFindingRemediation && (bool) $this->user()?->can('Update Audits');
    }

    public function rules(): array
    {
        return AuditFindingRemediationManager::followUpRules();
    }
}
