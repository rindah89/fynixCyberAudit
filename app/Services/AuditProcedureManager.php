<?php

namespace App\Services;

use App\Enums\AuditProcedureMethod;
use App\Enums\AuditProcedureOutcome;
use App\Enums\AuditWorkpaperReviewDecision;
use App\Enums\WorkflowStatus;
use App\Models\Audit;
use App\Models\AuditCloseoutSubmission;
use App\Models\AuditItem;
use App\Models\AuditProcedure;
use App\Models\AuditProcedureExecution;
use App\Models\AuditWorkpaperReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuditProcedureManager
{
    public function define(Audit $audit, User $actor, array $data): AuditProcedure
    {
        return DB::transaction(function () use ($audit, $actor, $data): AuditProcedure {
            $locked = Audit::query()->lockForUpdate()->findOrFail($audit->id);
            $this->authorizeManage($locked, $actor);
            $this->assertMutable($locked);
            $validated = Validator::make($data, self::definitionRules())->validate();
            if ($locked->procedures()->count() >= 250) {
                throw ValidationException::withMessages(['audit' => 'A governed work program is bounded to 250 procedure versions.']);
            }
            $item = AuditItem::query()->where('audit_id', $locked->id)->lockForUpdate()->findOrFail($validated['audit_item_id']);
            $assignee = User::query()->lockForUpdate()->findOrFail($validated['assigned_to']);
            if ($assignee->id !== $locked->manager_id && ! $locked->members()->whereKey($assignee->id)->exists()) {
                throw ValidationException::withMessages(['assigned_to' => 'The assignee must be the audit manager or a current audit member.']);
            }
            if (isset($validated['due_at']) && ($validated['due_at'] < $locked->start_date->toDateString() || $validated['due_at'] > $locked->end_date->toDateString())) {
                throw ValidationException::withMessages(['due_at' => 'The procedure due date must fall within the audit period.']);
            }
            $prior = AuditProcedure::query()->where('audit_id', $locked->id)->where('code', $validated['code'])->orderBy('version')->lockForUpdate()->get();
            if (AuditProcedure::query()->whereKey($prior->modelKeys())->whereDoesntHave('execution')->exists()) {
                throw ValidationException::withMessages(['code' => 'Execute the current procedure version before defining a later version.']);
            }
            $latest = $prior->last();
            if ($latest && $latest->execution?->review?->decision !== AuditWorkpaperReviewDecision::ReworkRequired) {
                throw ValidationException::withMessages(['code' => 'A later version is allowed only after the current workpaper review requires rework.']);
            }

            return AuditProcedure::query()->create($validated + [
                'audit_id' => $locked->id,
                'version' => ((int) $prior->max('version')) + 1,
                'status' => 'planned',
                'created_by' => $actor->id,
            ])->load(['auditItem.auditable', 'assignee:id,name', 'creator:id,name']);
        }, 3);
    }

    public function execute(AuditProcedure $procedure, User $actor, array $data): AuditProcedureExecution
    {
        $snapshotter = app(GovernedEvidenceSnapshotter::class);
        $snapshotBatch = Str::uuid()->toString();
        $retainedCopies = [];

        try {
            return DB::transaction(function () use ($procedure, $actor, $data, $snapshotter, $snapshotBatch, &$retainedCopies): AuditProcedureExecution {
                $auditId = AuditProcedure::query()->findOrFail($procedure->id)->audit_id;
                $audit = Audit::query()->lockForUpdate()->findOrFail($auditId);
                $locked = AuditProcedure::query()->where('audit_id', $audit->id)->lockForUpdate()->findOrFail($procedure->id);
                $item = AuditItem::query()->where('audit_id', $audit->id)->lockForUpdate()->findOrFail($locked->audit_item_id);
                $this->authorizeExecute($audit, $locked, $actor);
                $this->assertMutable($audit);
                if ($locked->execution()->exists()) {
                    throw ValidationException::withMessages(['procedure' => 'This procedure version has already been executed.']);
                }
                $validated = Validator::make($data, self::executionRules())->validate();
                if ($locked->planned_sample_size !== null && ($validated['sample_tested'] ?? 0) > $locked->planned_sample_size) {
                    throw ValidationException::withMessages(['sample_tested' => 'The tested sample cannot exceed the planned sample size.']);
                }
                $evidenceAttachmentIds = $validated['evidence_attachment_ids'] ?? [];
                unset($validated['evidence_attachment_ids']);
                $evidenceManifest = $evidenceAttachmentIds === [] ? [] : $snapshotter->snapshot(
                    $evidenceAttachmentIds, $actor, 'audit-procedure-execution', $snapshotBatch, $retainedCopies,
                );
                $executedAt = now();
                $snapshot = [
                    'procedure' => $locked->only(['id', 'audit_id', 'audit_item_id', 'version', 'code', 'title', 'objective', 'steps', 'method', 'population_description', 'planned_sample_size', 'assigned_to', 'due_at', 'created_by', 'created_at']),
                    'audit_item' => $item->only(['id', 'audit_id', 'auditable_id', 'auditable_type', 'user_id', 'status', 'auditor_notes', 'effectiveness', 'applicability']),
                    'auditable' => $item->auditable?->toArray(),
                ];
                $payload = $validated + ['evidence_manifest' => $evidenceManifest, 'procedure_snapshot' => $snapshot, 'executed_by' => $actor->id, 'executed_at' => $executedAt->toIso8601String()];
                $locked->update(['status' => 'completed']);
                $execution = $locked->execution()->create($payload + [
                    'executed_at' => $executedAt,
                    'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                ]);
                foreach ($evidenceManifest as $manifest) {
                    $execution->evidence()->create($manifest + ['linked_by' => $actor->id, 'linked_at' => $executedAt]);
                }

                return $execution->load(['executor:id,name', 'procedure.auditItem', 'evidence.linkedBy:id,name']);
            }, 3);
        } catch (\Throwable $exception) {
            $snapshotter->cleanup($retainedCopies);

            throw $exception;
        }
    }

    public function review(AuditProcedureExecution $execution, User $actor, array $data): AuditWorkpaperReview
    {
        return DB::transaction(function () use ($execution, $actor, $data): AuditWorkpaperReview {
            $procedureId = AuditProcedureExecution::query()->findOrFail($execution->id)->audit_procedure_id;
            $auditId = AuditProcedure::query()->findOrFail($procedureId)->audit_id;
            $audit = Audit::query()->lockForUpdate()->findOrFail($auditId);
            $procedure = AuditProcedure::query()->where('audit_id', $audit->id)->lockForUpdate()->findOrFail($procedureId);
            $locked = AuditProcedureExecution::query()->where('audit_procedure_id', $procedure->id)->lockForUpdate()->findOrFail($execution->id);
            $this->authorizeManage($audit, $actor);
            $this->assertMutable($audit);
            if ($locked->executed_by === $actor->id) {
                throw ValidationException::withMessages(['reviewer' => 'The workpaper reviewer must be independent from its executor.']);
            }
            if ($locked->review()->exists()) {
                throw ValidationException::withMessages(['execution' => 'This workpaper execution has already been reviewed.']);
            }
            $validated = Validator::make($data, self::reviewRules())->validate();
            $reviewedAt = now();
            $snapshot = $locked->only(['id', 'audit_procedure_id', 'outcome', 'result', 'exceptions', 'sample_tested', 'evidence_reference', 'evidence_manifest', 'procedure_snapshot', 'executed_by', 'executed_at', 'fingerprint']);
            $payload = $validated + ['execution_snapshot' => $snapshot, 'reviewed_by' => $actor->id, 'reviewed_at' => $reviewedAt->toIso8601String()];

            return $locked->review()->create($payload + [
                'reviewed_at' => $reviewedAt,
                'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            ])->load(['reviewer:id,name', 'execution.procedure']);
        }, 3);
    }

    public static function definitionRules(): array
    {
        return [
            'audit_id' => ['prohibited'], 'version' => ['prohibited'], 'status' => ['prohibited'], 'created_by' => ['prohibited'],
            'audit_item_id' => ['required', 'integer'], 'code' => ['required', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:255'], 'objective' => ['required', 'string', 'max:10000'],
            'steps' => ['required', 'string', 'max:30000'], 'method' => ['required', Rule::enum(AuditProcedureMethod::class)],
            'population_description' => ['nullable', 'string', 'max:10000'], 'planned_sample_size' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'assigned_to' => ['required', 'integer'], 'due_at' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public static function executionRules(): array
    {
        return [
            'audit_procedure_id' => ['prohibited'], 'procedure_snapshot' => ['prohibited'], 'executed_by' => ['prohibited'],
            'executed_at' => ['prohibited'], 'fingerprint' => ['prohibited'],
            'outcome' => ['required', Rule::enum(AuditProcedureOutcome::class)], 'result' => ['required', 'string', 'max:30000'],
            'exceptions' => ['nullable', 'string', 'max:30000'], 'sample_tested' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'evidence_reference' => ['nullable', 'string', 'max:2000'],
            'evidence_attachment_ids' => ['sometimes', 'array', 'max:20'],
            'evidence_attachment_ids.*' => ['integer', 'distinct'],
            'evidence_manifest' => ['prohibited'],
        ];
    }

    public static function reviewRules(): array
    {
        return [
            'audit_procedure_execution_id' => ['prohibited'], 'execution_snapshot' => ['prohibited'],
            'reviewed_by' => ['prohibited'], 'reviewed_at' => ['prohibited'], 'fingerprint' => ['prohibited'],
            'decision' => ['required', Rule::enum(AuditWorkpaperReviewDecision::class)],
            'review_summary' => ['required', 'string', 'max:30000'],
        ];
    }

    private function authorizeManage(Audit $audit, User $actor): void
    {
        abort_unless($actor->can('Update Audits') || $audit->manager_id === $actor->id, 403);
    }

    private function authorizeExecute(Audit $audit, AuditProcedure $procedure, User $actor): void
    {
        abort_unless($actor->can('Update Audits') || $audit->manager_id === $actor->id || $procedure->assigned_to === $actor->id, 403);
    }

    private function assertMutable(Audit $audit): void
    {
        if ($audit->status !== WorkflowStatus::INPROGRESS || AuditCloseoutSubmission::freezesAudit($audit->id)) {
            throw ValidationException::withMessages(['audit' => 'Procedures can change only while the audit is in progress and not submitted for closeout.']);
        }
    }
}
