<?php

namespace App\Http\Requests;

use App\Models\RegulatoryRequirementVersion;
use App\PolicyCompliance\RegulatoryChangeManager;
use Illuminate\Foundation\Http\FormRequest;

class AssessRegulatoryChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $version = $this->route('version');
        $requirement = $version instanceof RegulatoryRequirementVersion ? $version->requirement : null;

        return $requirement && ($this->user()?->can('Update Policies') || $requirement->owner_id === $this->user()?->id
            || $requirement->source()->where('owner_id', $this->user()?->id)->exists());
    }

    public function rules(): array
    {
        return RegulatoryChangeManager::assessmentRules() + [
            'regulatory_requirement_version_id' => ['prohibited'], 'assessment_version' => ['prohibited'],
            'requirement_snapshot' => ['prohibited'], 'policy_snapshots' => ['prohibited'], 'control_snapshots' => ['prohibited'],
            'content_fingerprint' => ['prohibited'], 'assessed_by' => ['prohibited'], 'assessed_at' => ['prohibited'],
        ];
    }
}
