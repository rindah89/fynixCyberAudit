<?php

namespace Database\Factories;

use App\Enums\Applicability;
use App\Enums\AuditProcedureMethod;
use App\Enums\Effectiveness;
use App\Enums\WorkflowStatus;
use App\Models\AuditEngagementBaseline;
use App\Models\AuditItem;
use App\Models\AuditProcedure;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditProcedureFactory extends Factory
{
    protected $model = AuditProcedure::class;

    public function definition(): array
    {
        return [
            'audit_item_id' => function (): int {
                $baseline = AuditEngagementBaseline::factory()->create();
                $baseline->audit->update(['status' => WorkflowStatus::INPROGRESS]);

                return AuditItem::factory()->for($baseline->audit)->create([
                    'status' => WorkflowStatus::INPROGRESS, 'effectiveness' => Effectiveness::UNKNOWN,
                    'applicability' => Applicability::APPLICABLE,
                ])->id;
            },
            'audit_id' => fn (array $attributes) => AuditItem::query()->findOrFail($attributes['audit_item_id'])->audit_id,
            'version' => 1, 'code' => fake()->unique()->bothify('AP-###'), 'title' => fake()->sentence(4),
            'objective' => fake()->paragraph(), 'steps' => fake()->paragraphs(2, true), 'method' => AuditProcedureMethod::Inspection,
            'population_description' => fake()->sentence(), 'planned_sample_size' => 10,
            'assigned_to' => function (array $attributes): int {
                $item = AuditItem::query()->findOrFail($attributes['audit_item_id']);
                $assignee = User::factory()->create();
                $item->audit->members()->syncWithoutDetaching([$assignee->id]);

                return $assignee->id;
            },
            'due_at' => fn (array $attributes): string => AuditItem::query()->findOrFail($attributes['audit_item_id'])->audit->start_date->toDateString(),
            'status' => 'planned',
            'created_by' => fn (array $attributes): int => (int) AuditItem::query()->findOrFail($attributes['audit_item_id'])->audit->manager_id,
        ];
    }
}
