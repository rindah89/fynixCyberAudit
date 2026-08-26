<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseRetentionManager;
use App\Models\ComplianceCaseClosureReport;
use App\Models\ComplianceCaseClosureReportReview;
use App\Models\ComplianceCaseRetentionClassification;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ComplianceCaseRetentionClassification> */
class ComplianceCaseRetentionClassificationFactory extends Factory
{
    protected $model = ComplianceCaseRetentionClassification::class;

    public function definition(): array
    {
        $package = ComplianceCaseClosureReport::factory()->create();
        $review = ComplianceCaseClosureReportReview::factory()->create(['compliance_case_closure_report_id' => $package->id]);
        $actor = User::factory()->create();
        $actor->assignRole('Security Admin');
        $case = $package->complianceCase;
        $classifiedAt = now()->startOfSecond();

        return [
            'compliance_case_id' => $case->id, 'version' => 1, 'policy_reference' => 'RET-FACTORY',
            'classification' => 'retain_7_years', 'starts_on' => $classifiedAt->toDateString(),
            'ends_on' => $classifiedAt->copy()->addYears(7)->toDateString(),
            'rationale' => 'Factory governed retention classification.', 'classified_by' => $actor->id,
            'classifier_snapshot' => $actor->only(['id', 'name', 'email']),
            'case_snapshot' => [
                'id' => $case->id, 'number' => $case->number, 'status' => $case->status->value,
                'closed_at' => $case->closed_at?->toIso8601String(), 'closure_package_id' => $package->id,
                'closure_package_fingerprint' => $package->fingerprint,
                'closure_package_review_fingerprint' => $review->fingerprint,
            ],
            'classified_at' => $classifiedAt, 'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseRetentionClassification $classification): void {
            $classification->fingerprint = hash('sha256', CanonicalJson::encode(
                app(ComplianceCaseRetentionManager::class)->payload($classification),
            ));
        });
    }
}
