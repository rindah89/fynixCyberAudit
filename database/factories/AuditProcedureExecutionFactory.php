<?php

namespace Database\Factories;

use App\Enums\AuditProcedureOutcome;
use App\Models\AuditProcedure;
use App\Models\AuditProcedureExecution;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditProcedureExecutionFactory extends Factory
{
    protected $model = AuditProcedureExecution::class;

    public function definition(): array
    {
        $executedAt = now();

        return [
            'audit_procedure_id' => AuditProcedure::factory()->state(['status' => 'completed']),
            'outcome' => AuditProcedureOutcome::Effective, 'result' => 'The selected items met the defined criteria.',
            'exceptions' => null, 'sample_tested' => 10, 'evidence_reference' => null,
            'procedure_snapshot' => fn (array $attributes): array => $this->snapshot(AuditProcedure::query()->findOrFail($attributes['audit_procedure_id'])),
            'executed_by' => fn (array $attributes): int => (int) AuditProcedure::query()->findOrFail($attributes['audit_procedure_id'])->assigned_to,
            'executed_at' => $executedAt,
            'fingerprint' => fn (array $attributes): string => hash('sha256', json_encode([
                'outcome' => $attributes['outcome'] instanceof AuditProcedureOutcome ? $attributes['outcome']->value : $attributes['outcome'],
                'result' => $attributes['result'], 'exceptions' => $attributes['exceptions'], 'sample_tested' => $attributes['sample_tested'],
                'evidence_reference' => $attributes['evidence_reference'], 'procedure_snapshot' => $attributes['procedure_snapshot'],
                'executed_by' => $attributes['executed_by'], 'executed_at' => $attributes['executed_at']->toIso8601String(),
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    private function snapshot(AuditProcedure $procedure): array
    {
        $item = $procedure->auditItem()->with('auditable')->firstOrFail();

        return [
            'procedure' => $procedure->only(['id', 'audit_id', 'audit_item_id', 'version', 'code', 'title', 'objective', 'steps', 'method', 'population_description', 'planned_sample_size', 'assigned_to', 'due_at', 'created_by', 'created_at']),
            'audit_item' => $item->only(['id', 'audit_id', 'auditable_id', 'auditable_type', 'user_id', 'status', 'auditor_notes', 'effectiveness', 'applicability']),
            'auditable' => $item->auditable?->toArray(),
        ];
    }
}
