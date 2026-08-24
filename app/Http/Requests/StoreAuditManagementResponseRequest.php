<?php

namespace App\Http\Requests;

use App\Models\AuditFinding;
use App\Services\AuditFindingManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreAuditManagementResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $finding = $this->route('finding');

        return $finding instanceof AuditFinding && ($finding->accountable_owner_id === $this->user()?->id || $this->user()?->isSuperAdmin());
    }

    public function rules(): array
    {
        return AuditFindingManager::responseRules();
    }
}
