<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseClosureReportManager;
use App\ComplianceCases\ComplianceCaseClosureReportReviewManager;
use App\Enums\ComplianceCaseClosureReportReviewDecision;
use App\Models\ComplianceCaseClosureReport;
use App\Models\ComplianceCaseClosureReportReview;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

class ComplianceCaseClosureReportReviewFactory extends Factory
{
    protected $model = ComplianceCaseClosureReportReview::class;

    public function definition(): array
    {
        return [
            'compliance_case_closure_report_id' => ComplianceCaseClosureReport::factory(),
            'decision' => ComplianceCaseClosureReportReviewDecision::Approved,
            'summary' => 'Factory independent closure-package approval.',
            'reviewed_by' => User::factory()->afterCreating(fn (User $user) => $user->givePermissionTo(['Manage Compliance Cases', 'Read Compliance Cases'])),
            'reviewer_snapshot' => [], 'closure_report_snapshot' => [],
            'reviewed_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseClosureReportReview $review): void {
            $review->loadMissing(['closureReport', 'reviewer']);
            $review->reviewer_snapshot = $review->reviewer->only(['id', 'name', 'email']);
            $review->closure_report_snapshot = ['id' => $review->closureReport->id, 'fingerprint' => $review->closureReport->fingerprint]
                + app(ComplianceCaseClosureReportManager::class)->payload($review->closureReport);
            $review->fingerprint = hash('sha256', CanonicalJson::encode(
                app(ComplianceCaseClosureReportReviewManager::class)->payload($review),
            ));
        });
    }
}
