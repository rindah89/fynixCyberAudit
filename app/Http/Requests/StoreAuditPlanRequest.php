<?php

namespace App\Http\Requests;

use App\Services\AuditUniverseManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreAuditPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Update Programs') ?? false;
    }

    public function rules(): array
    {
        return AuditUniverseManager::planRules() + ['status' => ['prohibited'], 'created_by' => ['prohibited'], 'approved_by' => ['prohibited'], 'approved_at' => ['prohibited'], 'approval_snapshot' => ['prohibited'], 'approval_fingerprint' => ['prohibited']];
    }
}
