<?php

namespace Database\Factories;

use App\Enums\Applicability;
use App\Enums\AuditFindingSeverity;
use App\Enums\Effectiveness;
use App\Enums\WorkflowStatus;
use App\Models\AuditEngagementBaseline;
use App\Models\AuditFinding;
use App\Models\AuditItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditFindingFactory extends Factory
{
    protected $model = AuditFinding::class;

    public function definition(): array
    {
        $raisedAt = now();

        return [
            'audit_item_id' => function (): int {
                $baseline = AuditEngagementBaseline::factory()->create();
                $baseline->audit->update(['status' => WorkflowStatus::INPROGRESS]);

                return AuditItem::factory()->for($baseline->audit)->create(['effectiveness' => Effectiveness::INEFFECTIVE, 'applicability' => Applicability::APPLICABLE])->id;
            },
            'audit_id' => fn (array $attributes): int => AuditItem::query()->findOrFail($attributes['audit_item_id'])->audit_id,
            'code' => fn (array $attributes): string => sprintf('AF-%06d-001', $attributes['audit_id']),
            'title' => 'Access reviews lack attributable completion evidence', 'severity' => AuditFindingSeverity::High,
            'condition' => 'Two sampled access reviews lacked reviewer timestamps.', 'criteria' => 'The control requires quarterly attributable review.',
            'cause' => 'The workflow permits completion without reviewer identity.', 'effect' => 'Inappropriate access may remain undetected.',
            'recommendation' => 'Require reviewer identity and timestamp before completion.', 'accountable_owner_id' => User::factory(),
            'source_snapshot' => fn (array $attributes): array => $this->snapshot(AuditItem::query()->findOrFail($attributes['audit_item_id']), User::query()->findOrFail($attributes['accountable_owner_id'])),
            'raised_by' => fn (array $attributes): int => (int) AuditItem::query()->findOrFail($attributes['audit_item_id'])->audit->manager_id,
            'raised_at' => $raisedAt,
            'fingerprint' => fn (array $attributes): string => hash('sha256', json_encode($this->payload($attributes), JSON_THROW_ON_ERROR)),
        ];
    }

    private function snapshot(AuditItem $item, User $owner): array
    {
        return ['audit' => $item->audit->only(['id', 'title', 'status', 'manager_id', 'start_date', 'end_date']),
            'audit_item' => $item->only(['id', 'audit_id', 'user_id', 'auditable_id', 'auditable_type', 'status', 'auditor_notes', 'effectiveness', 'applicability']),
            'auditable' => $item->auditable?->toArray(), 'accountable_owner' => $owner->only(['id', 'name', 'email'])];
    }

    private function payload(array $a): array
    {
        return ['audit_item_id' => $a['audit_item_id'], 'title' => $a['title'], 'severity' => $a['severity'] instanceof AuditFindingSeverity ? $a['severity']->value : $a['severity'],
            'condition' => $a['condition'], 'criteria' => $a['criteria'], 'cause' => $a['cause'], 'effect' => $a['effect'], 'recommendation' => $a['recommendation'],
            'accountable_owner_id' => $a['accountable_owner_id'], 'audit_id' => $a['audit_id'], 'code' => $a['code'], 'source_snapshot' => $a['source_snapshot'],
            'raised_by' => $a['raised_by'], 'raised_at' => $a['raised_at']->toIso8601String()];
    }
}
