<?php

namespace Database\Factories;

use App\Enums\ThirdPartyOffboardingCategory;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementMonitoringIndicator;
use App\Models\ThirdPartyEngagementOffboardingRequirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;

class ThirdPartyEngagementOffboardingRequirementFactory extends Factory
{
    protected $model = ThirdPartyEngagementOffboardingRequirement::class;

    public function definition(): array
    {
        return ['third_party_engagement_id' => function (): int {
            $indicator = ThirdPartyEngagementMonitoringIndicator::query()->findOrFail(ThirdPartyEngagementMonitoringIndicator::factory()->create()->getKey());

            return $indicator->third_party_engagement_id;
        },
            'version' => 1, 'category' => ThirdPartyOffboardingCategory::Access, 'title' => 'Factory offboarding control', 'acceptance_criteria' => 'Factory exit criteria.',
            'owner_id' => User::factory(), 'due_at' => today()->addMonth(), 'required' => true, 'engagement_snapshot' => [],
            'defined_by' => User::factory()->afterCreating(fn (User $user) => $user->givePermissionTo('Manage Third Party Risk')), 'defined_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64)];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyEngagementOffboardingRequirement $requirement): void {
            $engagement = ThirdPartyEngagement::query()->findOrFail($requirement->third_party_engagement_id);
            $engagement->load(['businessOwner:id,name,email', 'proposer:id,name,email', 'approver:id,name,email']);
            $payload = ['third_party_engagement_id' => $engagement->id, 'version' => $requirement->version, 'category' => $requirement->category->value, 'title' => $requirement->title,
                'acceptance_criteria' => $requirement->acceptance_criteria, 'owner_id' => $requirement->owner_id, 'due_at' => $requirement->due_at->toDateString(), 'required' => $requirement->required,
                'engagement_snapshot' => Arr::only($engagement->toArray(), ['id', 'vendor_id', 'code', 'name', 'service_description', 'business_owner_id', 'criticality', 'data_access', 'status', 'term_start_at', 'term_end_at', 'next_review_at', 'approval_snapshot', 'onboarding_readiness_snapshot', 'business_owner', 'proposer', 'approver']),
                'defined_by' => $requirement->defined_by, 'defined_at' => $requirement->defined_at->copy()->startOfSecond()->toIso8601String()];
            $requirement->forceFill($payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
        });
    }
}
