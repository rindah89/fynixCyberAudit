<?php

namespace Database\Factories;

use App\Models\AuditEffortBudget;
use App\Models\AuditProcedure;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditEffortBudgetFactory extends Factory
{
    protected $model = AuditEffortBudget::class;

    public function definition(): array
    {
        $setAt = now();

        return [
            'audit_procedure_id' => AuditProcedure::factory(),
            'audit_id' => fn (array $attributes): int => AuditProcedure::query()->findOrFail($attributes['audit_procedure_id'])->audit_id,
            'user_id' => fn (array $attributes): int => AuditProcedure::query()->findOrFail($attributes['audit_procedure_id'])->assigned_to,
            'version' => 1, 'planned_minutes' => 600, 'rationale' => 'Planned effort for the assigned procedure.',
            'allocation_snapshot' => fn (array $attributes): array => $this->snapshot(AuditProcedure::query()->findOrFail($attributes['audit_procedure_id'])),
            'set_by' => fn (array $attributes): int => (int) AuditProcedure::query()->findOrFail($attributes['audit_procedure_id'])->audit->manager_id,
            'set_at' => $setAt,
            'fingerprint' => fn (array $attributes): string => hash('sha256', json_encode($this->payload($attributes), JSON_THROW_ON_ERROR)),
        ];
    }

    private function snapshot(AuditProcedure $procedure): array
    {
        return [
            'audit' => $procedure->audit->only(['id', 'title', 'status', 'start_date', 'end_date', 'manager_id']),
            'procedure' => $procedure->only(['id', 'audit_id', 'audit_item_id', 'version', 'code', 'title', 'objective', 'steps', 'method', 'assigned_to', 'due_at', 'status']),
            'user' => $procedure->assignee->only(['id', 'name', 'email']),
        ];
    }

    private function payload(array $attributes): array
    {
        return [
            'audit_id' => $attributes['audit_id'], 'audit_procedure_id' => $attributes['audit_procedure_id'], 'user_id' => $attributes['user_id'],
            'version' => $attributes['version'], 'planned_minutes' => $attributes['planned_minutes'], 'rationale' => $attributes['rationale'],
            'allocation_snapshot' => $attributes['allocation_snapshot'], 'set_by' => $attributes['set_by'], 'set_at' => $attributes['set_at']->toIso8601String(),
        ];
    }
}
