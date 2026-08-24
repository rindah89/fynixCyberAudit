<?php

namespace App\Http\Requests;

use App\Services\AuditUniverseManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreAuditableEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('Update Programs') ?? false;
    }

    public function rules(): array
    {
        return AuditUniverseManager::entityRules() + self::serverOwned();
    }

    private static function serverOwned(): array
    {
        return [
            'created_by' => ['prohibited'], 'updated_by' => ['prohibited'], 'planning_status' => ['prohibited'],
            'inherent_score' => ['prohibited'], 'residual_score' => ['prohibited'], 'priority_band' => ['prohibited'],
        ];
    }
}
