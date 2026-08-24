<?php

namespace App\Http\Requests;

use App\Models\RegulatorySource;
use App\PolicyCompliance\RegulatoryChangeManager;
use Illuminate\Foundation\Http\FormRequest;

class StoreRegulatoryRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $source = $this->route('source');

        return $source instanceof RegulatorySource && ($this->user()?->can('Update Policies') || $source->owner_id === $this->user()?->id);
    }

    public function rules(): array
    {
        return RegulatoryChangeManager::requirementRules() + self::serverOwnedRules();
    }

    private static function serverOwnedRules(): array
    {
        return [
            'regulatory_source_id' => ['prohibited'], 'version' => ['prohibited'], 'created_by' => ['prohibited'],
            'published_by' => ['prohibited'], 'published_at' => ['prohibited'], 'source_snapshot' => ['prohibited'],
            'content_fingerprint' => ['prohibited'],
        ];
    }
}
