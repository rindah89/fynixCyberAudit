<?php

namespace App\Http\Requests;

use App\Models\AuditProcedureExecution;
use App\Services\AuditProcedureManager;
use Illuminate\Foundation\Http\FormRequest;

class ReviewAuditWorkpaperRequest extends FormRequest
{
    public function authorize(): bool
    {
        $execution = $this->route('execution');

        return $execution instanceof AuditProcedureExecution
            && ($this->user()?->can('Update Audits') || $execution->procedure?->audit?->manager_id === $this->user()?->id);
    }

    public function rules(): array
    {
        return AuditProcedureManager::reviewRules();
    }
}
