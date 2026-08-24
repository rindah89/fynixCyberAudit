<?php

namespace Database\Factories;

use App\Enums\ThirdPartyOnboardingCategory;
use App\Models\ThirdPartyContractRiskReview;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementOnboardingRequirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;

class ThirdPartyEngagementOnboardingRequirementFactory extends Factory
{
    protected $model = ThirdPartyEngagementOnboardingRequirement::class;

    public function definition(): array
    {
        return ['third_party_engagement_id' => function (): int {
            $review = ThirdPartyContractRiskReview::query()->findOrFail(ThirdPartyContractRiskReview::factory()->create()->getKey());

            return $review->third_party_engagement_id;
        },
            'version' => 1, 'category' => ThirdPartyOnboardingCategory::Security, 'title' => 'Factory onboarding control',
            'acceptance_criteria' => 'Factory acceptance criteria.', 'owner_id' => User::factory(), 'due_at' => today()->addMonth(), 'required' => true,
            'engagement_snapshot' => [], 'defined_by' => User::factory()->afterCreating(fn (User $user) => $user->givePermissionTo('Manage Third Party Risk')),
            'defined_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64)];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyEngagementOnboardingRequirement $requirement): void {
            $engagement = ThirdPartyEngagement::query()->findOrFail($requirement->third_party_engagement_id);
            $engagement->load(['businessOwner:id,name,email', 'proposer:id,name,email', 'approver:id,name,email']);
            $at = $requirement->defined_at->copy()->startOfSecond();
            $payload = ['third_party_engagement_id' => $engagement->id, 'version' => $requirement->version, 'category' => $requirement->category->value,
                'title' => $requirement->title, 'acceptance_criteria' => $requirement->acceptance_criteria, 'owner_id' => $requirement->owner_id,
                'due_at' => $requirement->due_at->toDateString(), 'required' => $requirement->required,
                'engagement_snapshot' => Arr::only($engagement->toArray(), ['id', 'vendor_id', 'code', 'name', 'service_description', 'business_owner_id', 'criticality', 'data_access', 'status', 'term_start_at', 'term_end_at', 'next_review_at', 'approval_snapshot', 'due_diligence_review_snapshot', 'business_owner', 'proposer', 'approver']),
                'defined_by' => $requirement->defined_by, 'defined_at' => $at->toIso8601String()];
            $requirement->forceFill($payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
        });
    }
}
