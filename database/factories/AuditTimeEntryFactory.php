<?php

namespace Database\Factories;

use App\Enums\AuditTimeEntryType;
use App\Models\AuditEffortBudget;
use App\Models\AuditProcedure;
use App\Models\AuditTimeEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditTimeEntryFactory extends Factory
{
    protected $model = AuditTimeEntry::class;

    public function definition(): array
    {
        $enteredAt = now();

        return [
            'audit_procedure_id' => AuditProcedure::factory(),
            'audit_id' => fn (array $attributes): int => AuditProcedure::query()->findOrFail($attributes['audit_procedure_id'])->audit_id,
            'user_id' => fn (array $attributes): int => AuditProcedure::query()->findOrFail($attributes['audit_procedure_id'])->assigned_to,
            'entry_type' => AuditTimeEntryType::Work, 'reverses_time_entry_id' => null,
            'work_date' => fn (array $attributes): string => AuditProcedure::query()->findOrFail($attributes['audit_procedure_id'])->audit->start_date->toDateString(),
            'minutes' => 120, 'activity' => 'Executed the assigned audit procedure.', 'notes' => fake()->sentence(), 'source_reference' => null,
            'budget_snapshot' => function (array $attributes): array {
                $budget = AuditEffortBudget::factory()->create(['audit_procedure_id' => $attributes['audit_procedure_id']]);

                return $budget->only(['id', 'version', 'planned_minutes', 'rationale', 'allocation_snapshot', 'fingerprint']);
            },
            'procedure_snapshot' => fn (array $attributes): array => AuditProcedure::query()->findOrFail($attributes['audit_procedure_id'])
                ->only(['id', 'audit_id', 'audit_item_id', 'version', 'code', 'title', 'objective', 'steps', 'method', 'assigned_to', 'due_at', 'status']),
            'entered_by' => fn (array $attributes): int => $attributes['user_id'], 'entered_at' => $enteredAt,
            'fingerprint' => fn (array $attributes): string => hash('sha256', json_encode($this->payload($attributes), JSON_THROW_ON_ERROR)),
        ];
    }

    private function payload(array $attributes): array
    {
        return [
            'audit_id' => $attributes['audit_id'], 'audit_procedure_id' => $attributes['audit_procedure_id'], 'user_id' => $attributes['user_id'],
            'entry_type' => $attributes['entry_type'] instanceof AuditTimeEntryType ? $attributes['entry_type']->value : $attributes['entry_type'],
            'reverses_time_entry_id' => $attributes['reverses_time_entry_id'], 'work_date' => $attributes['work_date'],
            'minutes' => $attributes['minutes'], 'activity' => $attributes['activity'], 'notes' => $attributes['notes'],
            'source_reference' => $attributes['source_reference'], 'budget_snapshot' => $attributes['budget_snapshot'],
            'procedure_snapshot' => $attributes['procedure_snapshot'], 'entered_by' => $attributes['entered_by'],
            'entered_at' => $attributes['entered_at']->toIso8601String(),
        ];
    }
}
