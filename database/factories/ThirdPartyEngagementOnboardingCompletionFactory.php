<?php

namespace Database\Factories;

use App\Models\ThirdPartyEngagementOnboardingCompletion;
use App\Models\ThirdPartyEngagementOnboardingRequirement;
use Illuminate\Database\Eloquent\Factories\Factory;

class ThirdPartyEngagementOnboardingCompletionFactory extends Factory
{
    protected $model = ThirdPartyEngagementOnboardingCompletion::class;

    public function definition(): array
    {
        return ['third_party_engagement_onboarding_requirement_id' => ThirdPartyEngagementOnboardingRequirement::factory(), 'version' => 1,
            'completion_summary' => 'Factory completion statement.', 'source_reference' => 'FACTORY-SOURCE', 'requirement_snapshot' => [],
            'completed_by' => fn (array $attributes): int => ThirdPartyEngagementOnboardingRequirement::query()->findOrFail($attributes['third_party_engagement_onboarding_requirement_id'])->owner_id,
            'completed_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64)];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyEngagementOnboardingCompletion $completion): void {
            $requirement = ThirdPartyEngagementOnboardingRequirement::query()->findOrFail($completion->third_party_engagement_onboarding_requirement_id);
            $at = $completion->completed_at->copy()->startOfSecond();
            $payload = ['third_party_engagement_onboarding_requirement_id' => $requirement->id, 'version' => $completion->version,
                'completion_summary' => $completion->completion_summary, 'source_reference' => $completion->source_reference,
                'requirement_snapshot' => $requirement->toArray(), 'completed_by' => $completion->completed_by, 'completed_at' => $at->toIso8601String()];
            $completion->forceFill($payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
        });
    }
}
