<?php

namespace Database\Factories;

use App\Enums\ContinuityActivationStatus;
use App\Models\BusinessImpactAnalysis;
use App\Models\ContinuityActivation;
use App\Models\RecoveryPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContinuityActivationFactory extends Factory
{
    protected $model = ContinuityActivation::class;

    public function definition(): array
    {
        $plan = RecoveryPlan::factory()->approved()->create();
        $service = $plan->businessService;
        $bia = $service->latestApprovedImpactAnalysis()->first() ?? BusinessImpactAnalysis::factory()->approved()->create(['business_service_id' => $service->id]);

        return ['recovery_plan_id' => $plan->id, 'business_service_id' => $service->id, 'activated_by' => $plan->owner_id,
            'status' => ContinuityActivationStatus::Activated, 'disruption_summary' => fake()->sentence(), 'business_impact' => fake()->paragraph(), 'started_at' => now(),
            'service_snapshot' => ['id' => $service->id, 'code' => $service->code, 'name' => $service->name, 'criticality' => $service->criticality->value, 'status' => $service->status, 'owner_id' => $service->owner_id, 'impact_analysis' => ['id' => $bia->id, 'version' => $bia->version, 'maximum_tolerable_downtime_minutes' => $bia->maximum_tolerable_downtime_minutes, 'recovery_time_objective_minutes' => $bia->recovery_time_objective_minutes, 'recovery_point_objective_minutes' => $bia->recovery_point_objective_minutes, 'approved_by' => $bia->approved_by, 'approved_at' => $bia->approved_at?->toIso8601String()]],
            'plan_snapshot' => ['id' => $plan->id, 'version' => $plan->version, 'title' => $plan->title, 'strategy' => $plan->strategy, 'activation_criteria' => $plan->activation_criteria, 'recovery_procedure' => $plan->recovery_procedure, 'communication_plan' => $plan->communication_plan, 'owner_id' => $plan->owner_id, 'approved_by' => $plan->approved_by, 'approved_at' => $plan->approved_at?->toIso8601String(), 'review_due_at' => $plan->review_due_at?->format('Y-m-d')]];
    }
}
