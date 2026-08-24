<?php

namespace Database\Factories;

use App\Enums\AuditWorkpaperReviewDecision;
use App\Models\AuditProcedureExecution;
use App\Models\AuditWorkpaperReview;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditWorkpaperReviewFactory extends Factory
{
    protected $model = AuditWorkpaperReview::class;

    public function definition(): array
    {
        $reviewedAt = now();

        return [
            'audit_procedure_execution_id' => AuditProcedureExecution::factory(),
            'decision' => AuditWorkpaperReviewDecision::Approved,
            'review_summary' => 'The workpaper supports its conclusion and documents the tested population.',
            'execution_snapshot' => fn (array $attributes): array => AuditProcedureExecution::query()->findOrFail($attributes['audit_procedure_execution_id'])
                ->only(['id', 'audit_procedure_id', 'outcome', 'result', 'exceptions', 'sample_tested', 'evidence_reference', 'evidence_manifest', 'procedure_snapshot', 'executed_by', 'executed_at', 'fingerprint']),
            'reviewed_by' => fn (array $attributes): int => (int) AuditProcedureExecution::query()->findOrFail($attributes['audit_procedure_execution_id'])->procedure->audit->manager_id,
            'reviewed_at' => $reviewedAt,
            'fingerprint' => fn (array $attributes): string => hash('sha256', json_encode([
                'decision' => $attributes['decision'] instanceof AuditWorkpaperReviewDecision ? $attributes['decision']->value : $attributes['decision'],
                'review_summary' => $attributes['review_summary'], 'execution_snapshot' => $attributes['execution_snapshot'],
                'reviewed_by' => $attributes['reviewed_by'], 'reviewed_at' => $attributes['reviewed_at']->toIso8601String(),
            ], JSON_THROW_ON_ERROR)),
        ];
    }
}
