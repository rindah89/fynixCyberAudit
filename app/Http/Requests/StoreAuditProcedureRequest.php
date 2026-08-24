<?php

namespace App\Http\Requests;

use App\Models\Audit;
use App\Services\AuditProcedureManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreAuditProcedureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $audit = $this->route('audit');

        return $audit instanceof Audit && ($this->user()?->can('Update Audits') || $audit->manager_id === $this->user()?->id);
    }

    public function rules(): array
    {
        return AuditProcedureManager::definitionRules();
    }
}
