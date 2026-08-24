<?php

namespace App\Http\Requests;

use App\Models\Audit;
use App\Services\AuditEffortManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreAuditEffortBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $audit = $this->route('audit');

        return $audit instanceof Audit && ($audit->manager_id === $this->user()?->id || $this->user()?->can('Update Audits'));
    }

    public function rules(): array
    {
        return AuditEffortManager::budgetRules();
    }
}
