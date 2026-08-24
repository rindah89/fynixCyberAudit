<?php

namespace App\Http\Requests;

use App\Models\AuditProcedure;
use App\Services\AuditProcedureManager;
use Illuminate\Foundation\Http\FormRequest;

class ExecuteAuditProcedureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $procedure = $this->route('procedure');

        return $procedure instanceof AuditProcedure && ($this->user()?->can('Update Audits') || $procedure->audit?->manager_id === $this->user()?->id || $procedure->assigned_to === $this->user()?->id);
    }

    public function rules(): array
    {
        return AuditProcedureManager::executionRules();
    }
}
