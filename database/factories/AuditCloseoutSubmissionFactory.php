<?php

namespace Database\Factories;

use App\Enums\Applicability;
use App\Enums\AuditOpinion;
use App\Enums\Effectiveness;
use App\Enums\WorkflowStatus;
use App\Models\Audit;
use App\Models\AuditCloseoutSubmission;
use App\Models\AuditEngagementBaseline;
use App\Models\AuditItem;
use App\Models\Control;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditCloseoutSubmissionFactory extends Factory
{
    protected $model = AuditCloseoutSubmission::class;

    public function definition(): array
    {
        return [
            'audit_id' => function (): int {
                $baseline = AuditEngagementBaseline::factory()->create();
                $baseline->audit->update(['status' => WorkflowStatus::INPROGRESS]);
                AuditItem::factory()->for($baseline->audit)->completed()->create([
                    'auditor_notes' => fake()->paragraph(), 'effectiveness' => Effectiveness::EFFECTIVE, 'applicability' => Applicability::APPLICABLE,
                ]);

                return $baseline->audit_id;
            },
            'version' => 1,
            'opinion' => AuditOpinion::Satisfactory,
            'executive_summary' => fake()->paragraph(),
            'scope_limitations' => null,
            'significant_matters' => fake()->paragraph(),
            'recommendations_summary' => fake()->paragraph(),
            'audit_snapshot' => fn (array $attributes): array => $this->auditSnapshot(Audit::query()->findOrFail($attributes['audit_id'])),
            'engagement_baseline_snapshot' => fn (array $attributes): array => Audit::query()->findOrFail($attributes['audit_id'])->engagementBaseline
                ->only(['id', 'audit_id', 'audit_plan_item_id', 'objective', 'scope', 'exclusions', 'team_user_ids', 'audit_snapshot', 'plan_snapshot', 'entity_assessment_snapshot', 'launched_by', 'launched_at', 'fingerprint']),
            'audit_item_snapshots' => function (array $attributes): array {
                return Audit::query()->findOrFail($attributes['audit_id'])->auditItems->map(function (AuditItem $item): array {
                    $control = Control::query()->findOrFail($item->auditable_id);

                    return [
                        ...$item->only(['id', 'audit_id', 'user_id', 'auditable_id', 'auditable_type', 'auditor_notes', 'status', 'effectiveness', 'applicability']),
                        'auditable_snapshot' => $control->only(['id', 'code', 'title', 'status', 'effectiveness', 'applicability', 'updated_at']),
                    ];
                })->all();
            },
            'data_request_snapshots' => [],
            'audit_procedure_snapshots' => [],
            'audit_effort_snapshots' => ['budgets' => [], 'time_entries' => [], 'summary' => ['planned_minutes' => 0, 'actual_minutes' => 0, 'variance_minutes' => 0, 'allocations' => []]],
            'audit_finding_snapshots' => [],
            'submitted_by' => fn (array $attributes): int => (int) Audit::query()->findOrFail($attributes['audit_id'])->manager_id,
            'submitted_at' => now(),
            'fingerprint' => fn (array $attributes): string => hash('sha256', json_encode($this->payload($attributes), JSON_THROW_ON_ERROR)),
        ];
    }

    private function auditSnapshot(Audit $audit): array
    {
        return [
            'id' => $audit->id, 'title' => $audit->title, 'description' => $audit->description, 'audit_type' => $audit->audit_type,
            'status' => $audit->status->value, 'start_date' => $audit->start_date->toDateString(), 'end_date' => $audit->end_date->toDateString(),
            'manager_id' => $audit->manager_id, 'program_id' => $audit->program_id, 'member_ids' => $audit->members()->orderBy('users.id')->pluck('users.id')->map(fn ($id): int => (int) $id)->all(),
        ];
    }

    private function payload(array $attributes): array
    {
        return [
            'audit_snapshot' => $attributes['audit_snapshot'], 'engagement_baseline_snapshot' => $attributes['engagement_baseline_snapshot'],
            'audit_item_snapshots' => $attributes['audit_item_snapshots'], 'data_request_snapshots' => $attributes['data_request_snapshots'],
            'audit_procedure_snapshots' => $attributes['audit_procedure_snapshots'],
            'audit_effort_snapshots' => $attributes['audit_effort_snapshots'],
            'audit_finding_snapshots' => $attributes['audit_finding_snapshots'],
            'opinion' => $attributes['opinion'] instanceof AuditOpinion ? $attributes['opinion']->value : $attributes['opinion'],
            'executive_summary' => $attributes['executive_summary'], 'scope_limitations' => $attributes['scope_limitations'],
            'significant_matters' => $attributes['significant_matters'], 'recommendations_summary' => $attributes['recommendations_summary'],
            'submitted_by' => $attributes['submitted_by'], 'submitted_at' => $attributes['submitted_at']->toIso8601String(), 'version' => $attributes['version'],
        ];
    }
}
