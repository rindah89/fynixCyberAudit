<?php

namespace App\Http\Requests;

use App\Models\AuditPlan;
use App\Services\AuditUniverseManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreAuditPlanItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $plan = $this->route('plan');

        return $plan instanceof AuditPlan && ($this->user()?->can('Update Programs') || $plan->manager_id === $this->user()?->id);
    }

    public function rules(): array
    {
        return AuditUniverseManager::planItemRules() + ['audit_plan_id' => ['prohibited'], 'entity_assessment_snapshot' => ['prohibited'], 'priority_rank' => ['prohibited'], 'created_by' => ['prohibited']];
    }
}
