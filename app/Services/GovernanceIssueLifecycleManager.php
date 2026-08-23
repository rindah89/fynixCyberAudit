<?php

namespace App\Services;

use App\Access\FileAccess;
use App\Enums\GovernanceIssueStatus;
use App\Enums\GovernanceIssueType;
use App\Enums\ResponseStatus;
use App\Models\Audit;
use App\Models\DataRequest;
use App\Models\DataRequestResponse;
use App\Models\FileAttachment;
use App\Models\GovernanceIssueLifecycle;
use App\Models\GovernanceIssueTransition;
use App\Models\RemediationProject;
use App\Models\RemediationTask;
use App\Models\User;
use App\Remediation\Remediation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
                $evidenceSnapshots = $this->prepareClosureEvidence(
                    $data['evidence_attachment_ids'],
                    $actor,
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
            foreach (collect($retainedCopies)->unique(fn (array $copy): string => $copy['disk'].'|'.$copy['path']) as $copy) {
                try {
                    Storage::disk($copy['disk'])->delete($copy['path']);
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

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

    /**
     * @param  list<int>  $attachmentIds
     * @return list<array{file_attachment_id: int, data_request_response_id_snapshot: int, response_status_snapshot: string, data_request_id_snapshot: int, audit_id_snapshot: int, disk_snapshot: string, file_name_snapshot: string, file_path_snapshot: string, file_size_snapshot: int, sha256: string}>
     */
    private function prepareClosureEvidence(
        array $attachmentIds,
        User $actor,
        string $snapshotBatch,
        array &$retainedCopies,
    ): array {
        $orderedIds = collect($attachmentIds)->map(fn ($id): int => (int) $id)->sort()->values();
        $attachments = FileAttachment::query()
            ->with(['audit.members', 'dataRequestResponse.dataRequest.audit.members'])
            ->whereKey($orderedIds)->lockForUpdate()->get()->keyBy('id');
        $responseIds = $attachments->pluck('data_request_response_id')->filter()->unique()->sort()->values();
        $responses = DataRequestResponse::query()->whereKey($responseIds)->lockForUpdate()->get()->keyBy('id');
        $requestIds = $responses->pluck('data_request_id')->unique()->sort()->values();
        $requests = DataRequest::query()->whereKey($requestIds)->lockForUpdate()->get()->keyBy('id');
        $auditIds = $requests->pluck('audit_id')->unique()->sort()->values();
        $audits = Audit::query()->whereKey($auditIds)->lockForUpdate()->get()->keyBy('id');
        DB::table('audit_user')->whereIn('audit_id', $auditIds)->orderBy('audit_id')->orderBy('user_id')->lockForUpdate()->get();
        $audits->load('members');
        $disk = setting('storage.driver', 'private');
        $storage = Storage::disk($disk);
        $fileAccess = app(FileAccess::class);
        $hasher = app(GovernedEvidenceHasher::class);
        $snapshots = [];
        $totalBytes = 0;

        foreach ($orderedIds as $index => $attachmentId) {
            /** @var FileAttachment|null $attachment */
            $attachment = $attachments->get($attachmentId);
            $response = $attachment ? $responses->get($attachment->data_request_response_id) : null;
            $request = $response ? $requests->get($response->data_request_id) : null;
            $audit = $request ? $audits->get($request->audit_id) : null;
            $errorKey = "evidence_attachment_ids.{$index}";
            if (! $attachment || ! $response || ! $request || ! $audit || $response->status !== ResponseStatus::ACCEPTED) {
                throw ValidationException::withMessages([$errorKey => 'Closure evidence must be an attachment on an accepted data-request response.']);
            }
            $request->setRelation('audit', $audit);
            $response->setRelation('dataRequest', $request);
            $attachment->setRelation('dataRequestResponse', $response);
            $attachment->setRelation('audit', $audit);
            if (! $fileAccess->canDownloadFileAttachment($actor, $attachment)) {
                throw ValidationException::withMessages([$errorKey => 'You must be authorized to access each closure evidence attachment.']);
            }

            $path = $fileAccess->normalizePath($attachment->file_path);
            $snapshotPath = "governance-closure-evidence/{$snapshotBatch}/{$attachment->id}";
            try {
                if (! $storage->exists($path)) {
                    throw new \RuntimeException('missing');
                }
                $declaredSize = $storage->size($path);
                if ($declaredSize > GovernedEvidenceHasher::MAX_FILE_BYTES || ($totalBytes + $declaredSize) > GovernedEvidenceHasher::MAX_TOTAL_BYTES) {
                    throw ValidationException::withMessages([$errorKey => 'Closure evidence is limited to 10 MiB per file and 50 MiB in total.']);
                }
                $stream = $storage->readStream($path);
                if (! is_resource($stream)) {
                    throw new \RuntimeException('unreadable');
                }
                try {
                    $hashResult = $hasher->snapshotStream($stream, $storage, $snapshotPath, $totalBytes, $errorKey);
                    $retainedCopies[] = ['disk' => $disk, 'path' => $snapshotPath];
                } finally {
                    fclose($stream);
                }
                $totalBytes += $hashResult['bytes'];
                $size = $hashResult['bytes'];
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (\Throwable) {
                throw ValidationException::withMessages([$errorKey => 'The closure evidence content must exist and be readable on the configured private storage disk.']);
            }

            $snapshots[] = [
                'file_attachment_id' => $attachment->id,
                'data_request_response_id_snapshot' => $response->id,
                'response_status_snapshot' => $response->status->value,
                'data_request_id_snapshot' => $request->id,
                'audit_id_snapshot' => $request->audit_id,
                'disk_snapshot' => $disk,
                'file_name_snapshot' => $attachment->file_name ?? basename($path),
                'file_path_snapshot' => $snapshotPath,
                'file_size_snapshot' => $size,
                'sha256' => $hashResult['sha256'],
            ];
        }

        return $snapshots;
    }
}
