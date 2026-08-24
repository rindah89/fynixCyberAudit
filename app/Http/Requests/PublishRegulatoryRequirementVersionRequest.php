<?php

namespace App\Http\Requests;

use App\Models\RegulatoryRequirement;
use App\PolicyCompliance\RegulatoryChangeManager;
use Illuminate\Foundation\Http\FormRequest;

class PublishRegulatoryRequirementVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $requirement = $this->route('requirement');

        return $requirement instanceof RegulatoryRequirement && ($this->user()?->can('Update Policies')
            || $requirement->owner_id === $this->user()?->id
            || $requirement->source()->where('owner_id', $this->user()?->id)->exists());
    }

    public function rules(): array
    {
        return RegulatoryChangeManager::versionRules() + [
            'regulatory_requirement_id' => ['prohibited'], 'version' => ['prohibited'], 'published_by' => ['prohibited'],
            'published_at' => ['prohibited'], 'source_snapshot' => ['prohibited'], 'content_fingerprint' => ['prohibited'],
        ];
    }
}
