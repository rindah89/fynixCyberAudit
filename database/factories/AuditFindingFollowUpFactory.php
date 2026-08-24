<?php

namespace Database\Factories;

use App\Enums\AuditFindingFollowUpOutcome;
use App\Models\AuditFindingFollowUp;
use App\Models\AuditFindingRemediation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditFindingFollowUpFactory extends Factory
{
    protected $model = AuditFindingFollowUp::class;

    public function definition(): array
    {
        $reviewedAt = now();

        return [
            'audit_finding_remediation_id' => AuditFindingRemediation::factory(),
            'version' => 1,
            'outcome' => AuditFindingFollowUpOutcome::Effective,
            'summary' => 'Independent reperformance confirms the corrective action operates as intended.',
            'evidence_reference' => 'Accepted audit response reference',
            'handoff_snapshot' => fn (array $a): array => AuditFindingRemediation::query()->findOrFail($a['audit_finding_remediation_id'])->toArray(),
            'task_snapshot' => function (array $a): array {
                $task = AuditFindingRemediation::query()->findOrFail($a['audit_finding_remediation_id'])->task;
                $task->update(['status' => 'Completed']);

                return $task->fresh()->toArray();
            },
            'reviewed_by' => function (): int {
                $reviewer = User::factory()->create();
                $reviewer->givePermissionTo('Update Audits');

                return $reviewer->id;
            },
            'reviewed_at' => $reviewedAt,
            'fingerprint' => fn (array $a): string => hash('sha256', json_encode([
                'audit_finding_remediation_id' => $a['audit_finding_remediation_id'], 'version' => $a['version'],
                'outcome' => $a['outcome'] instanceof AuditFindingFollowUpOutcome ? $a['outcome']->value : $a['outcome'],
                'summary' => $a['summary'], 'evidence_reference' => $a['evidence_reference'],
                'handoff_snapshot' => $a['handoff_snapshot'], 'task_snapshot' => $a['task_snapshot'],
                'reviewed_by' => $a['reviewed_by'], 'reviewed_at' => $a['reviewed_at']->toIso8601String(),
            ], JSON_THROW_ON_ERROR)),
        ];
    }
}
