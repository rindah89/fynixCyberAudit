<?php

namespace Database\Factories;

use App\Models\ThirdPartyEngagementOffboardingCompletion;
use App\Models\ThirdPartyEngagementOffboardingRequirement;
use Illuminate\Database\Eloquent\Factories\Factory;

class ThirdPartyEngagementOffboardingCompletionFactory extends Factory
{
    protected $model = ThirdPartyEngagementOffboardingCompletion::class;

    public function definition(): array
    {
        return ['third_party_engagement_offboarding_requirement_id' => ThirdPartyEngagementOffboardingRequirement::factory(), 'version' => 1, 'completion_summary' => 'Factory completion statement.', 'source_reference' => 'FACTORY-EXIT', 'requirement_snapshot' => [],
            'completed_by' => fn (array $attributes): int => ThirdPartyEngagementOffboardingRequirement::query()->findOrFail($attributes['third_party_engagement_offboarding_requirement_id'])->owner_id,
            'completed_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64)];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyEngagementOffboardingCompletion $completion): void {
            $requirement = ThirdPartyEngagementOffboardingRequirement::query()->findOrFail($completion->third_party_engagement_offboarding_requirement_id);
            $payload = ['third_party_engagement_offboarding_requirement_id' => $requirement->id, 'version' => $completion->version, 'completion_summary' => $completion->completion_summary,
                'source_reference' => $completion->source_reference, 'requirement_snapshot' => $requirement->toArray(), 'completed_by' => $completion->completed_by, 'completed_at' => $completion->completed_at->copy()->startOfSecond()->toIso8601String()];
            $completion->forceFill($payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
        });
    }
}
