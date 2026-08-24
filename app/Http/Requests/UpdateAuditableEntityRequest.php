<?php

namespace App\Http\Requests;

use App\Models\AuditableEntity;
use App\Services\AuditUniverseManager;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAuditableEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entity = $this->route('entity');

        return $entity instanceof AuditableEntity && ($this->user()?->can('Update Programs') ?? false);
    }

    public function rules(): array
    {
        return AuditUniverseManager::entityRules($this->route('entity')) + [
            'created_by' => ['prohibited'], 'updated_by' => ['prohibited'], 'planning_status' => ['prohibited'],
            'inherent_score' => ['prohibited'], 'residual_score' => ['prohibited'], 'priority_band' => ['prohibited'],
        ];
    }
}
