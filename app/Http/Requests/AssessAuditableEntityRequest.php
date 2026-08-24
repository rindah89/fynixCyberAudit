<?php

namespace App\Http\Requests;

use App\Models\AuditableEntity;
use App\Services\AuditUniverseManager;
use Illuminate\Foundation\Http\FormRequest;

class AssessAuditableEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entity = $this->route('entity');

        return $entity instanceof AuditableEntity && ($this->user()?->can('Update Programs') || $entity->owner_id === $this->user()?->id);
    }

    public function rules(): array
    {
        return AuditUniverseManager::assessmentRules() + [
            'auditable_entity_id' => ['prohibited'], 'version' => ['prohibited'], 'inherent_score' => ['prohibited'],
            'residual_score' => ['prohibited'], 'priority_band' => ['prohibited'], 'entity_snapshot' => ['prohibited'],
            'risk_snapshots' => ['prohibited'], 'control_snapshots' => ['prohibited'], 'governance_fingerprint' => ['prohibited'],
            'assessed_by' => ['prohibited'], 'assessed_at' => ['prohibited'],
        ];
    }
}
