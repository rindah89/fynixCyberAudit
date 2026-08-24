<?php

namespace Database\Factories;

use App\Enums\RegulatoryApplicability;
use App\Enums\RegulatoryImpact;
use App\Models\RegulatoryChangeAssessment;
use App\Models\RegulatoryRequirementVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RegulatoryChangeAssessmentFactory extends Factory
{
    protected $model = RegulatoryChangeAssessment::class;

    public function definition(): array
    {
        return [
            'regulatory_requirement_version_id' => RegulatoryRequirementVersion::factory(), 'assessment_version' => 1,
            'applicability' => RegulatoryApplicability::NotApplicable, 'impact' => RegulatoryImpact::Low,
            'summary' => fake()->sentence(), 'rationale' => fake()->paragraph(),
            'requirement_snapshot' => function (array $attributes): array {
                $version = RegulatoryRequirementVersion::query()->with('requirement.source')->findOrFail($attributes['regulatory_requirement_version_id']);

                return [
                    'requirement' => $version->requirement->only(['id', 'regulatory_source_id', 'code', 'owner_id']),
                    'version' => $version->only(['id', 'version', 'change_type', 'status', 'title', 'requirement_text', 'effective_at', 'expires_at', 'policy_ids', 'control_ids', 'source_snapshot', 'policy_snapshots', 'control_snapshots', 'content_fingerprint', 'published_by', 'published_at']),
                    'source' => $version->requirement->source->only(['id', 'code', 'title', 'authority', 'jurisdiction', 'reference_url', 'owner_id', 'status']),
                ];
            },
            'policy_snapshots' => fn (array $attributes): array => RegulatoryRequirementVersion::query()->findOrFail($attributes['regulatory_requirement_version_id'])->policy_snapshots,
            'control_snapshots' => fn (array $attributes): array => RegulatoryRequirementVersion::query()->findOrFail($attributes['regulatory_requirement_version_id'])->control_snapshots,
            'content_fingerprint' => fn (array $attributes): string => RegulatoryRequirementVersion::query()
                ->findOrFail($attributes['regulatory_requirement_version_id'])->content_fingerprint,
            'assessed_by' => User::factory(), 'assessed_at' => now(),
        ];
    }
}
