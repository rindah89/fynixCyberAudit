<?php

namespace App\Http\Requests;

use App\Models\AuditFinding;
use App\Services\AuditFindingRemediationManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreAuditFindingRemediationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $finding = $this->route('finding');

        return $finding instanceof AuditFinding
            && ($finding->audit->manager_id === $this->user()?->id || $this->user()?->can('Update Audits'))
            && ($this->user()?->isSuperAdmin() || $this->user()?->can('Manage Remediation'));
    }

    public function rules(): array
    {
        return ['remediation_project_id' => ['required', 'integer', 'exists:remediation_projects,id']] + AuditFindingRemediationManager::handoffRules();
    }
}
