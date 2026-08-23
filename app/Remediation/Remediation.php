<?php

namespace App\Remediation;

use App\Models\AuditItem;
use App\Models\RemediationProject;
use App\Models\RemediationTask;
use App\Models\User;
use App\Suite\PpmGateway;
use App\Support\Enterprise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Remediation
{
    /**
     * @param  array{name: string, status?: string, program_id?: int|null, start_date?: mixed, due_date?: mixed}  $data
     */
    public function createProject(User $actor, array $data): RemediationProject
    {
        $this->assertModule($actor);

        $project = RemediationProject::query()->create([
            'code' => $this->nextProjectCode(),
            'name' => $data['name'],
            'status' => $data['status'] ?? 'planning',
            'owner_id' => $actor->id,
            'program_id' => $data['program_id'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
        ]);

        $project->members()->create([
            'user_id' => $actor->id,
            'role' => 'owner',
        ]);

        $gateway = app(PpmGateway::class);
        if ($gateway->enabled()) {
            try {
                $gateway->publishProject($actor, $project);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $project;
    }

    /**
     * @param  array{priority?: string, type?: string, assignee_id?: int|null, due_date?: mixed, title?: string}  $data
     */
    public function createTaskFromAuditItem(User $actor, AuditItem $item, RemediationProject $project, array $data = []): RemediationTask
    {
        $this->assertModule($actor);

        if (! $project->isMember($actor)) {
            abort(403, 'You are not a member of this remediation project.');
        }

        $title = $data['title'] ?? ($item->auditable->title ?? 'Remediate audit finding');

        $task = RemediationTask::query()->create([
            'remediation_project_id' => $project->id,
            'number' => $this->nextTaskNumber($project),
            'title' => $title,
            'status' => 'Open',
            'priority' => $data['priority'] ?? 'Medium',
            'type' => $data['type'] ?? 'Remediation',
            'owner_id' => $actor->id,
            'assignee_id' => $data['assignee_id'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'weakness_description' => $item->auditor_notes,
            'audit_item_id' => $item->id,
        ]);

        $gateway = app(PpmGateway::class);
        if ($gateway->enabled()) {
            try {
                $gateway->publishTask($actor, $task);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $task;
    }

    /**
     * @param  array{priority?: string, assignee_id?: int|null, due_date: mixed}  $data
     */
    public function createTaskFromGovernanceIssue(User $actor, Model $issue, RemediationProject $project, array $data): RemediationTask
    {
        Enterprise::assertEnabled('remediation');
        if (! $actor->isSuperAdmin() && ! $actor->can('Manage Remediation') && ! $actor->can('Manage Issue Lifecycle')) {
            abort(403, 'You cannot manage governance issue remediation.');
        }
        if (! $project->isMember($actor)) {
            abort(403, 'You are not a member of this remediation project.');
        }

        $task = RemediationTask::query()->create([
            'remediation_project_id' => $project->id,
            'number' => $this->nextTaskNumber($project),
            'title' => $issue->title,
            'status' => 'Open',
            'priority' => $data['priority'] ?? 'Medium',
            'type' => 'Governance Issue',
            'owner_id' => $actor->id,
            'assignee_id' => $data['assignee_id'] ?? null,
            'due_date' => $data['due_date'],
            'weakness_description' => $issue->description,
        ]);

        DB::afterCommit(function () use ($actor, $task): void {
            $gateway = app(PpmGateway::class);
            if ($gateway->enabled()) {
                try {
                    $gateway->publishTask($actor, $task);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        });

        return $task;
    }

    public function updateTaskStatus(User $actor, RemediationTask $task, string $status): RemediationTask
    {
        $this->assertModule($actor);

        if (! $task->project->isMember($actor)) {
            abort(403, 'You are not a member of this remediation project.');
        }

        $task->update(['status' => $status]);

        return $task->fresh();
    }

    private function assertModule(User $actor): void
    {
        Enterprise::assertEnabled('remediation');

        if ($actor->isSuperAdmin() || $actor->can('Manage Remediation')) {
            return;
        }

        abort(403, 'You cannot manage remediations.');
    }

    private function nextProjectCode(): string
    {
        $last = RemediationProject::query()->orderByDesc('id')->value('code');
        $n = 1;
        if (is_string($last) && preg_match('/RP-(\d+)/', $last, $matches)) {
            $n = ((int) $matches[1]) + 1;
        }

        return sprintf('RP-%03d', $n);
    }

    private function nextTaskNumber(RemediationProject $project): string
    {
        $count = $project->tasks()->count() + 1;

        return sprintf('%s-%03d', $project->code, $count);
    }
}
