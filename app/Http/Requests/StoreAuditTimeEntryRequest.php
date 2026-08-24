<?php

namespace App\Http\Requests;

use App\Models\Audit;
use App\Services\AuditEffortManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreAuditTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $audit = $this->route('audit');

        return $audit instanceof Audit && ($audit->manager_id === $this->user()?->id || $audit->members()->whereKey($this->user()?->id)->exists());
    }

    public function rules(): array
    {
        return AuditEffortManager::entryRules();
    }
}
