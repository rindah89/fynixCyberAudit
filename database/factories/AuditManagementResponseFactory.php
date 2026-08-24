<?php

namespace Database\Factories;

use App\Enums\AuditManagementPosition;
use App\Models\AuditFinding;
use App\Models\AuditManagementResponse;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditManagementResponseFactory extends Factory
{
    protected $model = AuditManagementResponse::class;

    public function definition(): array
    {
        $respondedAt = now();

        return [
            'audit_finding_id' => AuditFinding::factory(), 'version' => 1, 'position' => AuditManagementPosition::Agreed,
            'response' => 'Management agrees with the finding.', 'action_plan' => 'Enforce reviewer identity and timestamps.',
            'target_date' => now()->addMonth()->toDateString(),
            'finding_snapshot' => fn (array $a): array => AuditFinding::query()->findOrFail($a['audit_finding_id'])->only(['id', 'audit_id', 'audit_item_id', 'code', 'title', 'severity', 'condition', 'criteria', 'cause', 'effect', 'recommendation', 'accountable_owner_id', 'source_snapshot', 'raised_by', 'raised_at', 'fingerprint']),
            'responded_by' => fn (array $a): int => (int) AuditFinding::query()->findOrFail($a['audit_finding_id'])->accountable_owner_id,
            'responded_at' => $respondedAt,
            'fingerprint' => fn (array $a): string => hash('sha256', json_encode([
                'position' => $a['position'] instanceof AuditManagementPosition ? $a['position']->value : $a['position'], 'response' => $a['response'],
                'action_plan' => $a['action_plan'], 'target_date' => $a['target_date'], 'audit_finding_id' => $a['audit_finding_id'],
                'version' => $a['version'], 'finding_snapshot' => $a['finding_snapshot'], 'responded_by' => $a['responded_by'],
                'responded_at' => $a['responded_at']->toIso8601String(),
            ], JSON_THROW_ON_ERROR)),
        ];
    }
}
