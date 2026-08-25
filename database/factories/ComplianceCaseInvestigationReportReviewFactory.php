<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseInvestigationReportManager;
use App\Enums\ComplianceCaseInvestigationReportDecision;
use App\Models\ComplianceCaseInvestigationReport;
use App\Models\ComplianceCaseInvestigationReportReview;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

class ComplianceCaseInvestigationReportReviewFactory extends Factory
{
    protected $model = ComplianceCaseInvestigationReportReview::class;

    public function definition(): array
    {
        return [
            'compliance_case_investigation_report_id' => ComplianceCaseInvestigationReport::factory(),
            'decision' => ComplianceCaseInvestigationReportDecision::Approved,
            'summary' => 'Factory independent investigation-report approval.',
            'reviewed_by' => User::factory()->afterCreating(fn (User $user) => $user->givePermissionTo('Manage Compliance Cases')),
            'reviewer_snapshot' => [], 'report_snapshot' => [], 'reviewed_at' => now()->startOfSecond(),
            'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseInvestigationReportReview $review): void {
            $review->loadMissing(['report', 'reviewer']);
            $manager = app(ComplianceCaseInvestigationReportManager::class);
            $review->reviewer_snapshot = $review->reviewer->only(['id', 'name', 'email']);
            $review->report_snapshot = ['id' => $review->report->id, 'fingerprint' => $review->report->fingerprint]
                + $manager->reportPayload($review->report);
            $review->fingerprint = hash('sha256', CanonicalJson::encode($manager->reviewPayload($review)));
        });
    }
}
