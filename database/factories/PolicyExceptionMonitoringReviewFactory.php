<?php

namespace Database\Factories;

use App\Enums\PolicyExceptionMonitoringOutcome;
use App\Models\PolicyExceptionDecision;
use App\Models\PolicyExceptionMonitoringReview;
use App\Models\User;
use App\PolicyCompliance\PolicyExceptionGovernanceManager;
use App\PolicyCompliance\PolicyExceptionMonitoringManager;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PolicyExceptionMonitoringReview> */
class PolicyExceptionMonitoringReviewFactory extends Factory
{
    public function definition(): array
    {
        $decision = PolicyExceptionDecision::factory()->create();
        $exception = $decision->exception->fresh();
        $reviewer = User::factory()->create();
        $reviewedAt = now()->startOfSecond();
        $nextReviewAt = min(
            $reviewedAt->copy()->addDays((int) $exception->review_frequency_days),
            $exception->expiration_date->endOfDay(),
        );
        $approvedContext = collect($exception->governance_snapshot)
            ->only(['policy', 'approved_revision', 'revision_governance_status', 'deleted_at'])->all();
        $currentContext = app(PolicyExceptionGovernanceManager::class)->currentPolicyContext($exception->policy);
        $snapshot = PolicyExceptionMonitoringManager::snapshot(
            $exception,
            $decision,
            $approvedContext,
            $currentContext,
            $currentContext === $approvedContext,
        );
        $payload = [
            'policy_exception_id' => $exception->id,
            'version' => 1,
            'outcome' => PolicyExceptionMonitoringOutcome::Effective->value,
            'review_summary' => 'Independent governed monitoring review.',
            'control_effectiveness' => 'The compensating control operated during the review period.',
            'evidence_reference' => 'FACTORY-REFERENCE',
            'exception_snapshot' => $snapshot,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => $reviewedAt->toISOString(),
            'next_review_at' => $nextReviewAt->toISOString(),
        ];

        return $payload + [
            'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (PolicyExceptionMonitoringReview $review): void {
            $review->exception->update([
                'latest_monitoring_outcome' => $review->outcome,
                'next_review_at' => $review->next_review_at,
            ]);
        });
    }
}
