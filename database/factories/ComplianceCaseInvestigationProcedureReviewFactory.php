<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseInvestigationProcedureExecutionManager;
use App\Enums\ComplianceCaseInvestigationProcedureReviewDecision;
use App\Models\ComplianceCaseInvestigationProcedureExecution;
use App\Models\ComplianceCaseInvestigationProcedureReview;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

class ComplianceCaseInvestigationProcedureReviewFactory extends Factory
{
    protected $model = ComplianceCaseInvestigationProcedureReview::class;

    public function definition(): array
    {
        return [
            'compliance_case_investigation_procedure_execution_id' => ComplianceCaseInvestigationProcedureExecution::factory(),
            'decision' => ComplianceCaseInvestigationProcedureReviewDecision::Approved,
            'summary' => 'Factory supervisory approval.',
            'reviewed_by' => User::factory()->afterCreating(fn (User $user) => $user->givePermissionTo('Manage Compliance Cases')),
            'reviewer_snapshot' => [], 'execution_snapshot' => [],
            'reviewed_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseInvestigationProcedureReview $review): void {
            $review->loadMissing(['execution.executor', 'reviewer']);
            if ($review->reviewed_by === $review->execution->executed_by) {
                $reviewer = User::factory()->create();
                $reviewer->givePermissionTo('Manage Compliance Cases');
                $review->reviewed_by = $reviewer->id;
                $review->setRelation('reviewer', $reviewer);
            }
            $review->reviewer_snapshot = $review->reviewer->only(['id', 'name', 'email']);
            $manager = app(ComplianceCaseInvestigationProcedureExecutionManager::class);
            $review->execution_snapshot = ['id' => $review->execution->id, 'fingerprint' => $review->execution->fingerprint] + $manager->payload($review->execution);
            $review->fingerprint = hash('sha256', CanonicalJson::encode($manager->reviewPayload($review)));
        });
    }
}
