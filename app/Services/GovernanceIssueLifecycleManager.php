<?php

namespace App\Services;

use App\Enums\GovernanceIssueStatus;
use App\Enums\GovernanceIssueType;
use App\Models\GovernanceIssueLifecycle;
use App\Models\GovernanceIssueTransition;
use App\Models\RemediationProject;
use App\Models\RemediationTask;
use App\Models\User;
use App\Remediation\Remediation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GovernanceIssueLifecycleManager
{
    public function resolve(GovernanceIssueType $type, int $id): Model
    {
        return $type->findOrFail($id);
    }

    public function register(Model $issue, User $actor): GovernanceIssueLifecycle
    {
        $this->assertSupported($issue);
        $lifecycle = $issue->lifecycle()->firstOrCreate([], ['status' => GovernanceIssueStatus::Open]);
        if (! $lifecycle->transitions()->exists()) {
            $this->recordTransition($lifecycle, null, GovernanceIssueStatus::Open, $actor, 'Issue opened by the source governance workflow.');
        }

        return $lifecycle;
    }

    public function handoff(Model $issue, User $actor, array $data): GovernanceIssueLifecycle
    {
        $this->assertManage($actor);

        return DB::transaction(function () use ($issue, $actor, $data): GovernanceIssueLifecycle {
            [$issue, $lifecycle] = $this->lock($issue);
            $this->assertStatus($lifecycle, GovernanceIssueStatus::Open);
            $project = RemediationProject::query()->lockForUpdate()->findOrFail($data['remediation_project_id']);
            if (! $project->isMember($actor)) {
                abort(403, 'You are not a member of this remediation project.');
            }
            $task = app(Remediation::class)->createTaskFromGovernanceIssue($actor, $issue, $project, $data);
            $from = $lifecycle->status;
            $lifecycle->update([
                'status' => GovernanceIssueStatus::InRemediation,
                'remediation_task_id' => $task->id, 'due_at' => $data['due_date'],
                'verification_summary' => null, 'evidence_reference' => null,
                'verified_by' => null, 'verified_at' => null, 'closed_by' => null, 'closed_at' => null,
            ]);
            $issue->updateQuietly(['status' => GovernanceIssueStatus::InRemediation->value, 'remediation_task_id' => $task->id]);
            $this->recordTransition($lifecycle, $from, GovernanceIssueStatus::InRemediation, $actor, $data['rationale'], $task);

            return $this->load($lifecycle, $issue);
        }, 3);
    }

    public function requestVerification(Model $issue, User $actor, string $rationale): GovernanceIssueLifecycle
    {
        $this->assertManage($actor);

        return DB::transaction(function () use ($issue, $actor, $rationale): GovernanceIssueLifecycle {
            [$issue, $lifecycle] = $this->lock($issue);
            $this->assertStatus($lifecycle, GovernanceIssueStatus::InRemediation);
            $task = RemediationTask::query()->lockForUpdate()->findOrFail($lifecycle->remediation_task_id);
            if (! $this->taskIsComplete($task)) {
                throw ValidationException::withMessages(['remediation_task' => 'The linked remediation task must be completed before verification.']);
            }
            $from = $lifecycle->status;
            $lifecycle->update(['status' => GovernanceIssueStatus::Verification]);
            $issue->updateQuietly(['status' => GovernanceIssueStatus::Verification->value]);
            $this->recordTransition($lifecycle, $from, GovernanceIssueStatus::Verification, $actor, $rationale, $task);

            return $this->load($lifecycle, $issue);
        }, 3);
    }

    public function close(Model $issue, User $actor, array $data): GovernanceIssueLifecycle
    {
        if (! $actor->can('Verify Issue Closure')) {
            abort(403, 'You cannot verify issue closure.');
        }

        $snapshotBatch = Str::uuid()->toString();
        $retainedCopies = [];

        try {
            return DB::transaction(function () use ($issue, $actor, $data, $snapshotBatch, &$retainedCopies): GovernanceIssueLifecycle {
                [$issue, $lifecycle] = $this->lock($issue);
                $this->assertStatus($lifecycle, GovernanceIssueStatus::Verification);
                $task = RemediationTask::query()->lockForUpdate()->findOrFail($lifecycle->remediation_task_id);
                if (in_array($actor->id, array_filter([$issue->owner_id, $task->owner_id, $task->assignee_id]), true)) {
                    throw ValidationException::withMessages(['verifier' => 'Closure must be verified by a user independent of the issue and remediation task owners or assignee.']);
                }
                if (! $this->taskIsComplete($task)) {
                    throw ValidationException::withMessages(['remediation_task' => 'The remediation task must remain completed at closure.']);
                }
                $evidenceSnapshots = app(GovernedEvidenceSnapshotter::class)->snapshot(
                    $data['evidence_attachment_ids'],
                    $actor,
                    'issue-closure',
                    $snapshotBatch,
                    $retainedCopies,
                );
                $from = $lifecycle->status;
                $now = now();
                $lifecycle->update([
                    'status' => GovernanceIssueStatus::Closed,
                    'verification_summary' => $data['verification_summary'], 'evidence_reference' => $data['evidence_reference'] ?? null,
                    'verified_by' => $actor->id, 'verified_at' => $now, 'closed_by' => $actor->id, 'closed_at' => $now,
                ]);
                $issue->updateQuietly(['status' => GovernanceIssueStatus::Closed->value]);
                $transition = $this->recordTransition($lifecycle, $from, GovernanceIssueStatus::Closed, $actor, 'Independent closure verification completed.', $task, $data['verification_summary'], $data['evidence_reference'] ?? null);
                foreach ($evidenceSnapshots as $snapshot) {
                    $lifecycle->closureEvidence()->create($snapshot + [
                        'governance_issue_transition_id' => $transition->id,
                        'linked_by' => $actor->id,
                        'linked_at' => $now,
                    ]);
                }

                return $this->load($lifecycle, $issue);
            }, 3);
        } catch (\Throwable $exception) {
            app(GovernedEvidenceSnapshotter::class)->cleanup($retainedCopies);

            throw $exception;
        }
    }

    public function reopen(Model $issue, User $actor, string $rationale): GovernanceIssueLifecycle
    {
        $this->assertManage($actor);

        return DB::transaction(function () use ($issue, $actor, $rationale): GovernanceIssueLifecycle {
            [$issue, $lifecycle] = $this->lock($issue);
            $this->assertStatus($lifecycle, GovernanceIssueStatus::Closed);
            $task = $lifecycle->remediation_task_id ? RemediationTask::query()->lockForUpdate()->find($lifecycle->remediation_task_id) : null;
            $from = $lifecycle->status;
            $lifecycle->update([
                'status' => GovernanceIssueStatus::Open,
                'verification_summary' => null, 'evidence_reference' => null,
                'verified_by' => null, 'verified_at' => null, 'closed_by' => null, 'closed_at' => null,
            ]);
            $issue->updateQuietly(['status' => GovernanceIssueStatus::Open->value]);
            $this->recordTransition($lifecycle, $from, GovernanceIssueStatus::Open, $actor, $rationale, $task);

            return $this->load($lifecycle, $issue);
        }, 3);
    }

    public function show(Model $issue, User $actor): GovernanceIssueLifecycle
    {
        $this->assertCanView($issue, $actor);

        return $this->load($issue->lifecycle()->firstOrFail(), $issue);
    }

    private function assertManage(User $actor): void
    {
        if (! $actor->can('Manage Issue Lifecycle')) {
            abort(403, 'You cannot manage governance issue lifecycles.');
        }
    }

    private function assertCanView(Model $issue, User $actor): void
    {
        if ((int) $issue->owner_id !== $actor->id && ! $actor->can('Manage Issue Lifecycle') && ! $actor->can('Verify Issue Closure')) {
            abort(403, 'You cannot view this governance issue lifecycle.');
        }
    }

    private function assertSupported(Model $issue): void
    {
        if (! collect(GovernanceIssueType::cases())->contains(fn (GovernanceIssueType $type): bool => $issue instanceof ($type->modelClass()))) {
            throw new \InvalidArgumentException('Unsupported governance issue type.');
        }
    }

    private function assertStatus(GovernanceIssueLifecycle $lifecycle, GovernanceIssueStatus $required): void
    {
        if ($lifecycle->status !== $required) {
            throw ValidationException::withMessages(['status' => "This action requires issue status {$required->value}."]);
        }
    }

    private function taskIsComplete(RemediationTask $task): bool
    {
        return in_array(strtolower(trim($task->status)), ['completed', 'closed', 'done', 'resolved'], true);
    }

    private function lock(Model $issue): array
    {
        $class = $issue::class;
        $lockedIssue = $class::query()->lockForUpdate()->findOrFail($issue->id);
        $lifecycle = $lockedIssue->lifecycle()->lockForUpdate()->firstOrFail();

        return [$lockedIssue, $lifecycle];
    }

    private function recordTransition(GovernanceIssueLifecycle $lifecycle, ?GovernanceIssueStatus $from, GovernanceIssueStatus $to, User $actor, string $rationale, ?RemediationTask $task = null, ?string $verificationSummary = null, ?string $evidenceReference = null): GovernanceIssueTransition
    {
        return $lifecycle->transitions()->create([
            'from_status' => $from, 'to_status' => $to, 'transitioned_by' => $actor->id,
            'rationale' => $rationale, 'remediation_task_id_snapshot' => $task?->id,
            'remediation_task_snapshot' => $task?->only(['id', 'remediation_project_id', 'number', 'title', 'status', 'priority', 'owner_id', 'assignee_id', 'due_date']),
            'verification_summary_snapshot' => $verificationSummary,
            'evidence_reference' => $evidenceReference, 'transitioned_at' => now(),
        ]);
    }

    private function load(GovernanceIssueLifecycle $lifecycle, Model $issue): GovernanceIssueLifecycle
    {
        $lifecycle->setRelation('issue', $issue->fresh());

        return $lifecycle->load(['remediationTask', 'verifier:id,name', 'closer:id,name', 'transitions.actor:id,name', 'closureEvidence.linkedBy:id,name']);
    }
}
