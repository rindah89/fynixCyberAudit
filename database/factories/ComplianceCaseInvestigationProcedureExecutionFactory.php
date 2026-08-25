<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseInvestigationPlanManager;
use App\ComplianceCases\ComplianceCaseInvestigationProcedureExecutionManager;
use App\Enums\ComplianceCaseInvestigationProcedureResult;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseInvestigationPlan;
use App\Models\ComplianceCaseInvestigationPlanReview;
use App\Models\ComplianceCaseInvestigationProcedureExecution;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

class ComplianceCaseInvestigationProcedureExecutionFactory extends Factory
{
    protected $model = ComplianceCaseInvestigationProcedureExecution::class;

    public function definition(): array
    {
        return [
            'compliance_case_investigation_plan_id' => function (): int {
                $review = ComplianceCaseInvestigationPlanReview::factory()->create();

                return $review->compliance_case_investigation_plan_id;
            },
            'compliance_case_id' => fn (array $attributes): int => ComplianceCaseInvestigationPlan::query()->findOrFail($attributes['compliance_case_investigation_plan_id'])->compliance_case_id,
            'procedure_index' => 1, 'procedure_text' => 'Establish material facts',
            'result' => ComplianceCaseInvestigationProcedureResult::Completed,
            'summary' => 'Factory procedure conclusion.', 'findings' => 'Factory retained findings.', 'source_reference' => null,
            'executed_by' => fn (array $attributes): int => (int) ComplianceCaseInvestigationPlan::query()->findOrFail($attributes['compliance_case_investigation_plan_id'])->authored_by,
            'executor_snapshot' => [], 'plan_snapshot' => [], 'case_snapshot' => [],
            'executed_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseInvestigationProcedureExecution $execution): void {
            $execution->loadMissing(['plan.review', 'executor']);
            $case = $execution->plan->complianceCase;
            $case->forceFill(['status' => ComplianceCaseStatus::Investigating, 'investigation_summary' => 'Factory investigation in progress.'])->save();
            $event = ComplianceCaseEvent::factory()->for($case)->state([
                'version' => ((int) $case->events()->max('version')) + 1,
            ])->create();
            $planManager = app(ComplianceCaseInvestigationPlanManager::class);
            $execution->compliance_case_id = $case->id;
            $execution->procedure_text = $execution->plan->procedures[$execution->procedure_index - 1];
            $execution->executor_snapshot = $execution->executor->only(['id', 'name', 'email']);
            $execution->plan_snapshot = ['id' => $execution->plan->id] + $planManager->planPayload($execution->plan) + [
                'fingerprint' => $execution->plan->fingerprint,
                'review' => ['id' => $execution->plan->review->id] + $planManager->reviewPayload($execution->plan->review) + ['fingerprint' => $execution->plan->review->fingerprint],
            ];
            $execution->case_snapshot = $planManager->caseSnapshot($case, $event);
            $execution->fingerprint = hash('sha256', CanonicalJson::encode(app(ComplianceCaseInvestigationProcedureExecutionManager::class)->payload($execution)));
        });
    }
}
