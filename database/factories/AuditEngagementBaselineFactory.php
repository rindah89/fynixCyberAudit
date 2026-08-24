<?php

namespace Database\Factories;

use App\Enums\AuditPlanStatus;
use App\Enums\WorkflowStatus;
use App\Models\Audit;
use App\Models\AuditEngagementBaseline;
use App\Models\AuditPlan;
use App\Models\AuditPlanItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditEngagementBaselineFactory extends Factory
{
    protected $model = AuditEngagementBaseline::class;

    public function definition(): array
    {
        return [
            'audit_plan_item_id' => AuditPlanItem::factory()->for(AuditPlan::factory()->state([
                'status' => AuditPlanStatus::Approved,
                'approved_by' => User::factory(),
                'approved_at' => now(),
                'approval_snapshot' => ['factory' => true],
                'approval_fingerprint' => hash('sha256', 'factory-approved-plan'),
            ]), 'plan'),
            'audit_id' => function (array $attributes): int {
                $item = AuditPlanItem::query()->findOrFail($attributes['audit_plan_item_id']);

                return Audit::factory()->withManager()->create([
                    'audit_type' => 'controls',
                    'status' => WorkflowStatus::NOTSTARTED,
                    'start_date' => $item->planned_start_at,
                    'end_date' => $item->planned_end_at,
                ])->id;
            },
            'objective' => fake()->paragraph(),
            'scope' => fake()->paragraph(),
            'exclusions' => fake()->sentence(),
            'team_user_ids' => fn (array $attributes): array => [(int) Audit::query()->findOrFail($attributes['audit_id'])->manager_id],
            'audit_snapshot' => function (array $attributes): array {
                $audit = Audit::query()->findOrFail($attributes['audit_id']);

                return [
                    'id' => $audit->id, 'title' => $audit->title, 'description' => $audit->description,
                    'audit_type' => $audit->audit_type, 'status' => $audit->status->value,
                    'start_date' => $audit->start_date->toDateString(), 'end_date' => $audit->end_date->toDateString(),
                    'manager_id' => $audit->manager_id, 'program_id' => $audit->program_id,
                ];
            },
            'plan_snapshot' => function (array $attributes): array {
                $item = AuditPlanItem::query()->with('plan')->findOrFail($attributes['audit_plan_item_id']);

                return [
                    'plan' => $item->plan->only(['id', 'plan_year', 'name', 'objective', 'manager_id', 'approved_by', 'approved_at', 'approval_fingerprint']),
                    'item' => $item->only(['id', 'auditable_entity_id', 'auditable_entity_assessment_id', 'status', 'planned_start_at', 'planned_end_at', 'rationale', 'priority_rank']),
                ];
            },
            'entity_assessment_snapshot' => fn (array $attributes): array => AuditPlanItem::query()->findOrFail($attributes['audit_plan_item_id'])->entity_assessment_snapshot,
            'launched_by' => User::factory(),
            'launched_at' => now(),
            'fingerprint' => fn (array $attributes): string => hash('sha256', json_encode([
                'audit_snapshot' => $attributes['audit_snapshot'],
                'objective' => $attributes['objective'],
                'scope' => $attributes['scope'],
                'exclusions' => $attributes['exclusions'],
                'team_user_ids' => $attributes['team_user_ids'],
                'plan_snapshot' => $attributes['plan_snapshot'],
                'entity_assessment_snapshot' => $attributes['entity_assessment_snapshot'],
                'launched_by' => $attributes['launched_by'],
                'launched_at' => $attributes['launched_at']->toIso8601String(),
            ], JSON_THROW_ON_ERROR)),
        ];
    }
}
