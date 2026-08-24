<?php

namespace Database\Factories;

use App\Models\ComplianceCase;
use App\Models\ComplianceCaseEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

class ComplianceCaseEventFactory extends Factory
{
    protected $model = ComplianceCaseEvent::class;

    public function definition(): array
    {
        $recordedAt = now()->startOfSecond();

        return [
            'compliance_case_id' => ComplianceCase::factory(), 'version' => 1,
            'after_snapshot' => function (array $attributes): array {
                $case = ComplianceCase::query()->with(['opener:id,name,email', 'assignee:id,name,email'])->findOrFail($attributes['compliance_case_id']);

                return $case->only([
                    'id', 'number', 'title', 'category', 'priority', 'status', 'allegation', 'source_channel', 'source_reference',
                    'reporter_reference', 'confidential', 'due_at', 'triage_summary', 'investigation_summary', 'resolution_summary',
                    'closure_summary', 'opened_at', 'resolved_at', 'closed_at', 'governed_at',
                ]) + [
                    'opened_by' => $case->opener?->only(['id', 'name', 'email']),
                    'assigned_to' => $case->assignee?->only(['id', 'name', 'email']),
                ];
            },
            'event_type' => fn (array $attributes): string => (int) $attributes['version'] === 1 ? 'opened' : 'updated',
            'before_snapshot' => fn (array $attributes): ?array => (int) $attributes['version'] === 1
                ? null : array_replace($attributes['after_snapshot'], ['source_reference' => 'Prior factory source reference.']),
            'summary' => fn (array $attributes): string => (int) $attributes['version'] === 1
                ? 'Factory governed case opening.' : 'Factory governed case update.',
            'recorded_by' => fn (array $attributes): int => (int) ComplianceCase::query()->findOrFail($attributes['compliance_case_id'])->opened_by,
            'recorded_at' => $recordedAt,
            'fingerprint' => fn (array $attributes): string => hash('sha256', json_encode([
                'compliance_case_id' => $attributes['compliance_case_id'], 'version' => $attributes['version'],
                'event_type' => $attributes['event_type'], 'before_snapshot' => $attributes['before_snapshot'],
                'after_snapshot' => $attributes['after_snapshot'], 'summary' => $attributes['summary'],
                'recorded_by' => $attributes['recorded_by'], 'recorded_at' => $attributes['recorded_at']->toIso8601String(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }
}
