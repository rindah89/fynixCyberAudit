<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseReopenManager;
use App\Models\ComplianceCaseReopenProposal;
use App\Models\ComplianceCaseReopenReview;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ComplianceCaseReopenReview> */
class ComplianceCaseReopenReviewFactory extends Factory
{
    protected $model = ComplianceCaseReopenReview::class;

    public function definition(): array
    {
        $proposal = ComplianceCaseReopenProposal::factory()->create();
        $reviewer = User::factory()->create();
        $reviewer->assignRole('Security Admin');
        $reviewedAt = now()->startOfSecond();

        return [
            'compliance_case_reopen_proposal_id' => $proposal->id, 'decision' => 'rejected',
            'summary' => 'Factory independent reopen rejection.', 'reviewed_by' => $reviewer->id,
            'reviewer_snapshot' => $reviewer->only(['id', 'name', 'email']),
            'proposal_snapshot' => ['id' => $proposal->id, 'fingerprint' => $proposal->fingerprint]
                + app(ComplianceCaseReopenManager::class)->proposalPayload($proposal),
            'reviewed_at' => $reviewedAt, 'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseReopenReview $review): void {
            $review->fingerprint = hash('sha256', CanonicalJson::encode(
                app(ComplianceCaseReopenManager::class)->reviewPayload($review),
            ));
        });
    }
}
