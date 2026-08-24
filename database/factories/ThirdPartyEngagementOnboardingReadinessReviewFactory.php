<?php

namespace Database\Factories;

use App\Enums\ThirdPartyOnboardingDecision;
use App\Models\ThirdPartyContractRiskReview;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementOnboardingCompletion;
use App\Models\ThirdPartyEngagementOnboardingReadinessReview;
use App\Models\ThirdPartyEngagementOnboardingRequirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;

class ThirdPartyEngagementOnboardingReadinessReviewFactory extends Factory
{
    protected $model = ThirdPartyEngagementOnboardingReadinessReview::class;

    /** @var array{completion: ThirdPartyEngagementOnboardingCompletion}|null */
    private ?array $context = null;

    public function definition(): array
    {
        return ['third_party_engagement_id' => function (): int {
            $completion = ThirdPartyEngagementOnboardingCompletion::query()->findOrFail($this->context()['completion']->id);
            $requirement = ThirdPartyEngagementOnboardingRequirement::query()->findOrFail($completion->third_party_engagement_onboarding_requirement_id);

            return $requirement->third_party_engagement_id;
        },
            'version' => 1, 'decision' => ThirdPartyOnboardingDecision::Ready, 'conditions' => null, 'summary' => 'Factory readiness accepted.',
            'next_review_at' => today()->addMonths(6), 'engagement_snapshot' => [], 'requirements_snapshot' => [],
            'engagement_event_fingerprint' => str_repeat('0', 64), 'contract_review_fingerprint' => str_repeat('0', 64),
            'reviewed_by' => User::factory()->afterCreating(fn (User $user) => $user->givePermissionTo('Manage Third Party Risk')),
            'reviewed_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64)];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyEngagementOnboardingReadinessReview $review): void {
            $completion = ThirdPartyEngagementOnboardingCompletion::query()->findOrFail($this->context()['completion']->id);
            $requirement = ThirdPartyEngagementOnboardingRequirement::query()->findOrFail($completion->third_party_engagement_onboarding_requirement_id);
            $engagement = ThirdPartyEngagement::query()->findOrFail($requirement->third_party_engagement_id);
            $engagement->load(['businessOwner:id,name,email', 'proposer:id,name,email', 'approver:id,name,email']);
            $contract = ThirdPartyContractRiskReview::query()->where('third_party_engagement_id', $engagement->id)->orderByDesc('version')->firstOrFail();
            $at = $review->reviewed_at->copy()->startOfSecond();
            $payload = ['third_party_engagement_id' => $engagement->id, 'version' => $review->version, 'decision' => $review->decision->value,
                'conditions' => $review->conditions, 'summary' => $review->summary, 'next_review_at' => $review->next_review_at->toDateString(),
                'engagement_snapshot' => Arr::only($engagement->toArray(), ['id', 'vendor_id', 'code', 'name', 'service_description', 'business_owner_id', 'criticality', 'data_access', 'status', 'term_start_at', 'term_end_at', 'next_review_at', 'approval_snapshot', 'due_diligence_review_snapshot', 'business_owner', 'proposer', 'approver']),
                'requirements_snapshot' => [['requirement' => $requirement->toArray(), 'latest_completion' => $completion->toArray()]],
                'engagement_event_fingerprint' => $engagement->events()->reorder()->orderByDesc('version')->value('fingerprint'),
                'contract_review_fingerprint' => $contract->fingerprint, 'reviewed_by' => $review->reviewed_by, 'reviewed_at' => $at->toIso8601String()];
            $review->forceFill($payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
        });
    }

    /** @return array{completion: ThirdPartyEngagementOnboardingCompletion} */
    private function context(): array
    {
        return $this->context ??= ['completion' => ThirdPartyEngagementOnboardingCompletion::factory()->create()];
    }
}
