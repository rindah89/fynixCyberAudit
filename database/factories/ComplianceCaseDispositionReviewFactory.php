<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseRetentionManager;
use App\Enums\ComplianceCaseDispositionDecision;
use App\Models\ComplianceCaseDispositionReview;
use App\Models\ComplianceCaseRetentionClassification;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ComplianceCaseDispositionReview> */
class ComplianceCaseDispositionReviewFactory extends Factory
{
    protected $model = ComplianceCaseDispositionReview::class;

    public function definition(): array
    {
        $classification = ComplianceCaseRetentionClassification::factory()->create();
        $reviewer = User::factory()->create();
        $reviewer->assignRole('Security Admin');
        $reviewedAt = now()->startOfSecond();

        return [
            'compliance_case_retention_classification_id' => $classification->id,
            'decision' => ComplianceCaseDispositionDecision::Approved,
            'summary' => 'Factory independent disposition approval.', 'reviewed_by' => $reviewer->id,
            'reviewer_snapshot' => $reviewer->only(['id', 'name', 'email']),
            'classification_snapshot' => ['id' => $classification->id, 'fingerprint' => $classification->fingerprint]
                + app(ComplianceCaseRetentionManager::class)->payload($classification),
            'reviewed_at' => $reviewedAt, 'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseDispositionReview $review): void {
            $review->fingerprint = hash('sha256', CanonicalJson::encode(
                app(ComplianceCaseRetentionManager::class)->reviewPayload($review),
            ));
        });
    }
}
