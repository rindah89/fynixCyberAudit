<?php

namespace App\Services;

use App\Enums\AuditFindingFollowUpOutcome;
use App\Enums\AuditManagementPosition;
use App\Models\Audit;
use App\Models\AuditFinding;
use App\Models\AuditFindingFollowUp;
use App\Models\AuditFindingRemediation;
use App\Models\AuditManagementResponse;
use App\Models\RemediationProject;
use App\Models\RemediationTask;
use App\Models\User;
use App\Remediation\Remediation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuditFindingRemediationManager
{
    private const COMPLETE_TASK_STATUSES = ['Completed', 'Closed', 'Done', 'Resolved'];

    public function handoff(AuditFinding $finding, User $actor, RemediationProject $project, array $data): AuditFindingRemediation
    {
        return DB::transaction(function () use ($finding, $actor, $project, $data): AuditFindingRemediation {
            $auditId = AuditFinding::query()->findOrFail($finding->id)->audit_id;
            $audit = Audit::query()->lockForUpdate()->findOrFail($auditId);
            abort_unless($audit->manager_id === $actor->id || $actor->can('Update Audits'), 403);
            abort_unless($actor->isSuperAdmin() || $actor->can('Manage Remediation'), 403);
            $lockedFinding = AuditFinding::query()->where('audit_id', $audit->id)->lockForUpdate()->findOrFail($finding->id);
            if ($lockedFinding->remediation()->exists()) {
                throw ValidationException::withMessages(['finding' => 'This finding already has a governed remediation handoff.']);
            }
            $response = AuditManagementResponse::query()->where('audit_finding_id', $lockedFinding->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            if (! in_array($response->position, [AuditManagementPosition::Agreed, AuditManagementPosition::PartiallyAgreed], true)) {
                throw ValidationException::withMessages(['finding' => 'Only an agreed or partially agreed latest management response can be handed to remediation.']);
            }
            $lockedProject = RemediationProject::query()->lockForUpdate()->findOrFail($project->id);
            abort_unless($lockedProject->isMember($actor), 403);
            $validated = Validator::make($data, self::handoffRules())->validate();
            $assigneeId = $validated['assignee_id'] ?? $lockedFinding->accountable_owner_id;
            $assignee = User::query()->lockForUpdate()->findOrFail($assigneeId);
            if ($assignee->trashed()) {
                throw ValidationException::withMessages(['assignee_id' => 'The remediation assignee must be active.']);
            }
            $task = app(Remediation::class)->createTaskFromAuditItem($actor, $lockedFinding->auditItem()->firstOrFail(), $lockedProject, [
                'title' => $lockedFinding->code.' '.$lockedFinding->title,
                'priority' => $validated['priority'] ?? $lockedFinding->severity->getLabel(),
                'assignee_id' => $assignee->id,
                'due_date' => $response->target_date,
            ]);
            $task->update(['audit_finding_id' => $lockedFinding->id]);
            $handedOffAt = now();
            $payload = [
                'audit_finding_id' => $lockedFinding->id,
                'audit_management_response_id' => $response->id,
                'remediation_task_id' => $task->id,
                'finding_snapshot' => $lockedFinding->toArray(),
                'response_snapshot' => $response->toArray(),
                'task_snapshot' => $task->fresh()->toArray(),
                'handed_off_by' => $actor->id,
                'handed_off_at' => $handedOffAt->toIso8601String(),
            ];
            $handoff = AuditFindingRemediation::query()->create($payload + [
                'handed_off_at' => $handedOffAt,
                'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            ]);

            return $handoff->load(['task', 'finding', 'response', 'handoffActor:id,name']);
        }, 3);
    }

    public function followUp(AuditFindingRemediation $remediation, User $actor, array $data): AuditFindingFollowUp
    {
        return DB::transaction(function () use ($remediation, $actor, $data): AuditFindingFollowUp {
            $findingId = AuditFindingRemediation::query()->findOrFail($remediation->id)->audit_finding_id;
            $auditId = AuditFinding::query()->findOrFail($findingId)->audit_id;
            $audit = Audit::query()->lockForUpdate()->findOrFail($auditId);
            $finding = AuditFinding::query()->where('audit_id', $audit->id)->lockForUpdate()->findOrFail($findingId);
            $handoff = AuditFindingRemediation::query()->where('audit_finding_id', $finding->id)->lockForUpdate()->findOrFail($remediation->id);
            $task = RemediationTask::query()->lockForUpdate()->findOrFail($handoff->remediation_task_id);
            abort_unless($actor->can('Update Audits'), 403);
            $responseActorIds = AuditManagementResponse::query()->where('audit_finding_id', $finding->id)->pluck('responded_by');
            abort_if(in_array($actor->id, array_filter([$audit->manager_id, $finding->accountable_owner_id, $task->owner_id, $task->assignee_id, $handoff->handed_off_by]), true) || $responseActorIds->contains($actor->id), 403, 'The follow-up reviewer must be independent of audit management and remediation ownership.');
            if (! in_array($task->status, self::COMPLETE_TASK_STATUSES, true)) {
                throw ValidationException::withMessages(['task' => 'The linked remediation task must be completed before effectiveness follow-up.']);
            }
            $validated = Validator::make($data, self::followUpRules())->validate();
            $prior = $handoff->followUps()->orderBy('version')->lockForUpdate()->get();
            if ($prior->contains(fn (AuditFindingFollowUp $followUp): bool => $followUp->outcome === AuditFindingFollowUpOutcome::Effective)) {
                throw ValidationException::withMessages(['finding' => 'An effective finding follow-up is final.']);
            }
            if ($prior->count() >= 20) {
                throw ValidationException::withMessages(['finding' => 'Finding follow-up history is bounded to 20 versions.']);
            }
            if ($prior->isNotEmpty() && $prior->last()->task_snapshot === $task->toArray()) {
                throw ValidationException::withMessages(['task' => 'A later follow-up requires a changed remediation-task snapshot.']);
            }
            $reviewedAt = now();
            $payload = [
                'audit_finding_remediation_id' => $handoff->id,
                'version' => ((int) $prior->max('version')) + 1,
                'outcome' => $validated['outcome'],
                'summary' => $validated['summary'],
                'evidence_reference' => $validated['evidence_reference'] ?? null,
                'handoff_snapshot' => $handoff->toArray(),
                'task_snapshot' => $task->toArray(),
                'reviewed_by' => $actor->id,
                'reviewed_at' => $reviewedAt->toIso8601String(),
            ];
            $followUp = AuditFindingFollowUp::query()->create($payload + [
                'reviewed_at' => $reviewedAt,
                'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            ]);

            return $followUp->load(['reviewer:id,name', 'remediation.task']);
        }, 3);
    }

    public static function handoffRules(): array
    {
        return [
            'priority' => ['nullable', 'string', 'max:50'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'audit_finding_id' => ['prohibited'], 'remediation_task_id' => ['prohibited'], 'fingerprint' => ['prohibited'],
        ];
    }

    public static function followUpRules(): array
    {
        return [
            'outcome' => ['required', Rule::enum(AuditFindingFollowUpOutcome::class)],
            'summary' => ['required', 'string', 'max:30000'],
            'evidence_reference' => ['nullable', 'string', 'max:2000'],
            'version' => ['prohibited'], 'handoff_snapshot' => ['prohibited'], 'task_snapshot' => ['prohibited'],
            'reviewed_by' => ['prohibited'], 'reviewed_at' => ['prohibited'], 'fingerprint' => ['prohibited'],
        ];
    }
}
