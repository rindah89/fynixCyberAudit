<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseReopenManager;
use App\ComplianceCases\ComplianceCaseRetentionManager;
use App\Models\ComplianceCaseClosureReport;
use App\Models\ComplianceCaseClosureReportReview;
use App\Models\ComplianceCaseReopenProposal;
use App\Models\ComplianceCaseRetentionClassification;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ComplianceCaseReopenProposal> */
class ComplianceCaseReopenProposalFactory extends Factory
{
    protected $model = ComplianceCaseReopenProposal::class;

    public function definition(): array
    {
        $package = ComplianceCaseClosureReport::factory()->create();
        $packageReview = ComplianceCaseClosureReportReview::factory()->create(['compliance_case_closure_report_id' => $package->id]);
        $actor = User::factory()->create();
        $actor->assignRole('Security Admin');
        $case = $package->complianceCase;
        $proposedAt = now()->startOfSecond();
        $retention = new ComplianceCaseRetentionClassification([
            'compliance_case_id' => $case->id, 'version' => 1, 'policy_reference' => 'RET-REOPEN',
            'classification' => 'retain', 'starts_on' => $proposedAt->toDateString(),
            'ends_on' => $proposedAt->copy()->addYears(7)->toDateString(),
            'rationale' => 'Factory current reopen retention context.', 'classified_by' => $actor->id,
            'classifier_snapshot' => $actor->only(['id', 'name', 'email']),
            'case_snapshot' => [
                'id' => $case->id, 'number' => $case->number, 'status' => $case->status->value,
                'closed_at' => $case->closed_at?->toIso8601String(), 'closure_package_id' => $package->id,
                'closure_package_fingerprint' => $package->fingerprint,
                'closure_package_review_fingerprint' => $packageReview->fingerprint,
            ],
            'classified_at' => $proposedAt,
        ]);
        $retention->fingerprint = hash('sha256', CanonicalJson::encode(
            app(ComplianceCaseRetentionManager::class)->payload($retention),
        ));
        $retention->save();

        return [
            'compliance_case_id' => $case->id, 'version' => 1,
            'summary' => 'Factory governed reopen proposal.', 'proposed_by' => $actor->id,
            'proposer_snapshot' => $actor->only(['id', 'name', 'email']),
            'case_snapshot' => [
                'id' => $case->id, 'number' => $case->number, 'status' => $case->status->value,
                'closure_summary' => $case->closure_summary, 'closed_at' => $case->closed_at?->toIso8601String(),
                'closure_package_id' => $package->id, 'closure_package_fingerprint' => $package->fingerprint,
                'closure_package_review_fingerprint' => $packageReview->fingerprint,
                'retention_classification_id' => $retention->id, 'retention_fingerprint' => $retention->fingerprint,
            ],
            'proposed_at' => $proposedAt, 'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseReopenProposal $proposal): void {
            $proposal->fingerprint = hash('sha256', CanonicalJson::encode(
                app(ComplianceCaseReopenManager::class)->proposalPayload($proposal),
            ));
        });
    }
}
