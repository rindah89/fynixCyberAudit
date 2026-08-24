<?php

namespace Database\Factories;

use App\Enums\RiskIndicatorDirection;
use App\Enums\ThirdPartyMonitoringCategory;
use App\Models\ThirdPartyContractRiskReview;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementMonitoringIndicator;
use App\Models\User;
use App\ThirdPartyRisk\ThirdPartyEngagementManager;
use App\ThirdPartyRisk\ThirdPartyEngagementOnboardingManager;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;

class ThirdPartyEngagementMonitoringIndicatorFactory extends Factory
{
    protected $model = ThirdPartyEngagementMonitoringIndicator::class;

    public function definition(): array
    {
        return ['third_party_engagement_id' => fn (): int => $this->activeEngagement()->id, 'version' => 1, 'code' => fake()->unique()->bothify('KPI-####'),
            'name' => 'Provider service availability', 'description' => 'Factory monitoring definition.', 'category' => ThirdPartyMonitoringCategory::ServiceLevel, 'unit' => '%',
            'direction' => RiskIndicatorDirection::LowerIsWorse, 'warning_threshold' => '99.900000', 'critical_threshold' => '99.500000',
            'frequency_days' => 30, 'owner_id' => User::factory(), 'measurement_method' => 'Review the retained provider report.',
            'engagement_snapshot' => [], 'contract_review_snapshot' => [], 'risk_approval_snapshot' => [], 'defined_by' => fn (array $attributes): int => ThirdPartyEngagement::query()->findOrFail($attributes['third_party_engagement_id'])->approved_by,
            'defined_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64)];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyEngagementMonitoringIndicator $indicator): void {
            $engagement = ThirdPartyEngagement::query()->findOrFail($indicator->third_party_engagement_id);
            $engagement->load(['businessOwner:id,name,email', 'proposer:id,name,email', 'approver:id,name,email']);
            $review = $engagement->contractRiskReviews()->latest('version')->firstOrFail();
            $at = $indicator->defined_at->copy()->startOfSecond();
            $payload = ['code' => strtoupper($indicator->code), 'name' => $indicator->name, 'description' => $indicator->description, 'category' => $indicator->category->value, 'unit' => $indicator->unit,
                'direction' => $indicator->direction->value, 'warning_threshold' => $indicator->warning_threshold, 'critical_threshold' => $indicator->critical_threshold,
                'frequency_days' => $indicator->frequency_days, 'owner_id' => $indicator->owner_id, 'measurement_method' => $indicator->measurement_method,
                'third_party_engagement_id' => $engagement->id, 'version' => $indicator->version,
                'engagement_snapshot' => Arr::only($engagement->toArray(), ['id', 'vendor_id', 'code', 'name', 'service_description', 'business_owner_id', 'criticality', 'data_access', 'status', 'term_start_at', 'term_end_at', 'next_review_at', 'approved_by', 'approved_at', 'activated_at', 'vendor_snapshot', 'approval_snapshot', 'governed_at', 'business_owner', 'proposer', 'approver']),
                'contract_review_snapshot' => $review->toArray(), 'risk_approval_snapshot' => $engagement->approval_snapshot, 'defined_by' => $indicator->defined_by, 'defined_at' => $at->toIso8601String()];
            $indicator->forceFill($payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
        });
    }

    private function activeEngagement(): ThirdPartyEngagement
    {
        $review = ThirdPartyContractRiskReview::factory()->create();
        $engagement = $review->engagement()->firstOrFail();
        $actor = User::query()->findOrFail($engagement->approved_by);
        $actor->givePermissionTo('Manage Third Party Risk');
        $owner = User::factory()->create();
        $reviewer = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        $onboarding = app(ThirdPartyEngagementOnboardingManager::class);
        $requirement = $onboarding->define($actor, $engagement, ['category' => 'security', 'title' => 'Factory onboarding control', 'acceptance_criteria' => 'Factory readiness criteria.', 'owner_id' => $owner->id, 'due_at' => today()->addMonth()->toDateString(), 'required' => true]);
        $onboarding->complete($owner, $requirement, ['completion_summary' => 'Factory onboarding complete.', 'source_reference' => 'FACTORY-ONBOARD']);
        $onboarding->review($reviewer, $engagement, ['decision' => 'ready', 'summary' => 'Factory readiness accepted.', 'next_review_at' => $engagement->next_review_at->toDateString()]);
        app(ThirdPartyEngagementManager::class)->transition($actor, $engagement, ['status' => 'active', 'summary' => 'Factory activation.']);

        return $engagement->refresh();
    }
}
