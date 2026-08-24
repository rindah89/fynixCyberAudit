<?php

namespace Database\Factories;

use App\Models\AuditFindingRemediation;
use App\Models\AuditManagementResponse;
use App\Models\RemediationProject;
use App\Models\RemediationTask;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditFindingRemediationFactory extends Factory
{
    protected $model = AuditFindingRemediation::class;

    public function definition(): array
    {
        $handedOffAt = now();

        return [
            'audit_management_response_id' => AuditManagementResponse::factory(),
            'audit_finding_id' => fn (array $a): int => AuditManagementResponse::query()->findOrFail($a['audit_management_response_id'])->audit_finding_id,
            'remediation_task_id' => function (array $a): int {
                $finding = AuditManagementResponse::query()->findOrFail($a['audit_management_response_id'])->finding;
                $manager = $finding->audit->manager;
                $manager->givePermissionTo('Manage Remediation');
                $project = RemediationProject::factory()->for($manager, 'owner')->create();
                $project->members()->create(['user_id' => $manager->id, 'role' => 'owner']);

                return RemediationTask::factory()->for($project, 'project')->forFinding($finding)->create([
                    'owner_id' => $manager->id, 'assignee_id' => $finding->accountable_owner_id,
                ])->id;
            },
            'finding_snapshot' => fn (array $a): array => AuditManagementResponse::query()->findOrFail($a['audit_management_response_id'])->finding->toArray(),
            'response_snapshot' => fn (array $a): array => AuditManagementResponse::query()->findOrFail($a['audit_management_response_id'])->toArray(),
            'task_snapshot' => fn (array $a): array => RemediationTask::query()->findOrFail($a['remediation_task_id'])->toArray(),
            'handed_off_by' => function (array $a): int {
                $manager = AuditManagementResponse::query()->findOrFail($a['audit_management_response_id'])->finding->audit->manager;
                $manager->givePermissionTo('Manage Remediation');

                return $manager->id;
            },
            'handed_off_at' => $handedOffAt,
            'fingerprint' => fn (array $a): string => hash('sha256', json_encode([
                'audit_finding_id' => $a['audit_finding_id'], 'audit_management_response_id' => $a['audit_management_response_id'],
                'remediation_task_id' => $a['remediation_task_id'], 'finding_snapshot' => $a['finding_snapshot'],
                'response_snapshot' => $a['response_snapshot'], 'task_snapshot' => $a['task_snapshot'],
                'handed_off_by' => $a['handed_off_by'], 'handed_off_at' => $a['handed_off_at']->toIso8601String(),
            ], JSON_THROW_ON_ERROR)),
        ];
    }
}
