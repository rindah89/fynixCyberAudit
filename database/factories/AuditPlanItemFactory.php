<?php

namespace Database\Factories;

use App\Enums\AuditPlanItemStatus;
use App\Models\AuditableEntityAssessment;
use App\Models\AuditPlan;
use App\Models\AuditPlanItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditPlanItemFactory extends Factory
{
    protected $model = AuditPlanItem::class;

    public function definition(): array
    {
        return [
            'audit_plan_id' => AuditPlan::factory(), 'auditable_entity_assessment_id' => AuditableEntityAssessment::factory(),
            'auditable_entity_id' => fn (array $attributes): int => AuditableEntityAssessment::query()->findOrFail($attributes['auditable_entity_assessment_id'])->auditable_entity_id,
            'status' => AuditPlanItemStatus::Planned, 'planned_start_at' => today()->startOfYear()->addMonth(), 'planned_end_at' => today()->startOfYear()->addMonths(2),
            'rationale' => fake()->paragraph(),
            'entity_assessment_snapshot' => function (array $attributes): array {
                $assessment = AuditableEntityAssessment::query()->findOrFail($attributes['auditable_entity_assessment_id']);

                return [
                    'entity' => $assessment->entity_snapshot,
                    'assessment' => $assessment->only(['id', 'version', 'inherent_score', 'residual_score', 'priority_band', 'rationale', 'risk_snapshots', 'control_snapshots', 'governance_fingerprint', 'next_assessment_at', 'assessed_by', 'assessed_at']),
                ];
            },
            'priority_rank' => function (array $attributes): int {
                $assessment = AuditableEntityAssessment::query()->findOrFail($attributes['auditable_entity_assessment_id']);
                $weight = match ($assessment->priority_band) {
                    'critical' => 4, 'high' => 3, 'medium' => 2, default => 1
                };

                return ($weight * 100) + $assessment->residual_score;
            },
            'created_by' => User::factory(),
        ];
    }
}
