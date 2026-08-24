<?php

namespace App\Http\Requests;

use App\Models\AuditTimeEntry;
use App\Services\AuditEffortManager;
use Illuminate\Foundation\Http\FormRequest;

class ReverseAuditTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entry = $this->route('entry');

        return $entry instanceof AuditTimeEntry && ($entry->user_id === $this->user()?->id || $entry->audit?->manager_id === $this->user()?->id || $this->user()?->can('Update Audits'));
    }

    public function rules(): array
    {
        return AuditEffortManager::reversalRules();
    }
}
