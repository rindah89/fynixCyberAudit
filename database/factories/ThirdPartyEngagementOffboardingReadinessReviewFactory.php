<?php

namespace Database\Factories;

use App\Enums\ThirdPartyOffboardingDecision;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementEvent;
use App\Models\ThirdPartyEngagementOffboardingCompletion;
use App\Models\ThirdPartyEngagementOffboardingReadinessReview;
use App\Models\ThirdPartyEngagementOffboardingRequirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;

class ThirdPartyEngagementOffboardingReadinessReviewFactory extends Factory
{
    protected $model = ThirdPartyEngagementOffboardingReadinessReview::class;

    private ?ThirdPartyEngagementOffboardingCompletion $completion = null;

    public function definition(): array
    {
        $completion = $this->completion();
        $requirement = ThirdPartyEngagementOffboardingRequirement::query()->findOrFail($completion->third_party_engagement_offboarding_requirement_id);

        return ['third_party_engagement_id' => $requirement->third_party_engagement_id, 'version' => 1, 'decision' => ThirdPartyOffboardingDecision::Ready, 'conditions' => null,
            'summary' => 'Factory exit readiness accepted.', 'engagement_snapshot' => [], 'requirements_snapshot' => [], 'engagement_event_fingerprint' => str_repeat('0', 64),
            'reviewed_by' => User::factory()->afterCreating(fn (User $user) => $user->givePermissionTo('Manage Third Party Risk')), 'reviewed_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64)];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyEngagementOffboardingReadinessReview $review): void {
            $completion = ThirdPartyEngagementOffboardingCompletion::query()->findOrFail($this->completion()->id);
            $requirement = ThirdPartyEngagementOffboardingRequirement::query()->findOrFail($completion->third_party_engagement_offboarding_requirement_id);
            $engagement = ThirdPartyEngagement::query()->findOrFail($requirement->third_party_engagement_id);
            $engagement->load(['businessOwner:id,name,email', 'proposer:id,name,email', 'approver:id,name,email']);
            $event = ThirdPartyEngagementEvent::query()->where('third_party_engagement_id', $engagement->id)->orderByDesc('version')->firstOrFail();
            $payload = ['third_party_engagement_id' => $engagement->id, 'version' => $review->version, 'decision' => $review->decision->value, 'conditions' => $review->conditions, 'summary' => $review->summary,
                'engagement_snapshot' => Arr::only($engagement->toArray(), ['id', 'vendor_id', 'code', 'name', 'service_description', 'business_owner_id', 'criticality', 'data_access', 'status', 'term_start_at', 'term_end_at', 'next_review_at', 'approval_snapshot', 'onboarding_readiness_snapshot', 'business_owner', 'proposer', 'approver']),
                'requirements_snapshot' => [['requirement' => $requirement->toArray(), 'latest_completion' => $completion->toArray()]], 'engagement_event_fingerprint' => $event->fingerprint,
                'reviewed_by' => $review->reviewed_by, 'reviewed_at' => $review->reviewed_at->copy()->startOfSecond()->toIso8601String()];
            $review->forceFill($payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
        });
    }

    private function completion(): ThirdPartyEngagementOffboardingCompletion
    {
        return $this->completion ??= ThirdPartyEngagementOffboardingCompletion::factory()->create();
    }
}
