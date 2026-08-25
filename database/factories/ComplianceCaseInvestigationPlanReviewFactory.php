<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseInvestigationPlanManager;
use App\Enums\ComplianceCaseInvestigationPlanDecision;
use App\Models\ComplianceCaseInvestigationPlan;
use App\Models\ComplianceCaseInvestigationPlanReview;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

class ComplianceCaseInvestigationPlanReviewFactory extends Factory
{
    protected $model = ComplianceCaseInvestigationPlanReview::class;

    public function definition(): array
    {
        return ['compliance_case_investigation_plan_id' => ComplianceCaseInvestigationPlan::factory(),
            'decision' => ComplianceCaseInvestigationPlanDecision::Approved, 'summary' => 'Factory independent plan approval.',
            'reviewed_by' => User::factory()->afterCreating(fn (User $user) => $user->givePermissionTo('Manage Compliance Cases')), 'reviewer_snapshot' => [], 'plan_snapshot' => [],
            'reviewed_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64)];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseInvestigationPlanReview $review): void {
            $review->loadMissing(['plan', 'reviewer']);
            $manager = app(ComplianceCaseInvestigationPlanManager::class);
            $review->reviewer_snapshot = $review->reviewer->only(['id', 'name', 'email']);
            $review->plan_snapshot = ['id' => $review->plan->id] + $manager->planPayload($review->plan) + ['fingerprint' => $review->plan->fingerprint];
            $review->fingerprint = hash('sha256', CanonicalJson::encode($manager->reviewPayload($review)));
        });
    }
}
