<?php

namespace App\Services;

use App\Enums\AuditFindingSeverity;
use App\Enums\AuditManagementPosition;
use App\Enums\WorkflowStatus;
use App\Models\Audit;
use App\Models\AuditCloseoutSubmission;
use App\Models\AuditFinding;
use App\Models\AuditItem;
use App\Models\AuditManagementResponse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuditFindingManager
{
    public const MAX_EVIDENCE_BYTES = 5_000_000;

    public function raise(Audit $audit, User $actor, array $data): AuditFinding
    {
        return DB::transaction(function () use ($audit, $actor, $data): AuditFinding {
            $locked = Audit::query()->lockForUpdate()->findOrFail($audit->id);
            $this->authorizeManage($locked, $actor);
            $this->assertMutable($locked);
            $validated = Validator::make($data, self::findingRules())->validate();
            if ($locked->governedFindings()->count() >= 500) {
                throw ValidationException::withMessages(['audit' => 'Governed finding history is bounded to 500 findings per audit.']);
            }
            $item = AuditItem::query()->with('auditable')->where('audit_id', $locked->id)->lockForUpdate()->findOrFail($validated['audit_item_id']);
            $owner = User::query()->lockForUpdate()->findOrFail($validated['accountable_owner_id']);
            if ($owner->trashed()) {
                throw ValidationException::withMessages(['accountable_owner_id' => 'The accountable management owner must be active.']);
            }
            $raisedAt = now();
            $sequence = $locked->governedFindings()->count() + 1;
            $snapshot = [
                'audit' => $locked->only(['id', 'title', 'status', 'manager_id', 'start_date', 'end_date']),
                'audit_item' => $item->only(['id', 'audit_id', 'user_id', 'auditable_id', 'auditable_type', 'status', 'auditor_notes', 'effectiveness', 'applicability']),
                'auditable' => $item->auditable?->toArray(),
                'accountable_owner' => $owner->only(['id', 'name', 'email']),
            ];
            $payload = $validated + [
                'audit_id' => $locked->id, 'code' => sprintf('AF-%06d-%03d', $locked->id, $sequence), 'source_snapshot' => $snapshot,
                'raised_by' => $actor->id, 'raised_at' => $raisedAt->toIso8601String(),
            ];

            $finding = AuditFinding::query()->create($payload + [
                'raised_at' => $raisedAt,
                'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            ]);
            $this->assertAggregateBound($locked);

            return $finding->load(['auditItem.auditable', 'accountableOwner:id,name', 'raiser:id,name']);
        }, 3);
    }

    public function respond(AuditFinding $finding, User $actor, array $data): AuditManagementResponse
    {
        return DB::transaction(function () use ($finding, $actor, $data): AuditManagementResponse {
            $auditId = AuditFinding::query()->findOrFail($finding->id)->audit_id;
            $audit = Audit::query()->lockForUpdate()->findOrFail($auditId);
            $locked = AuditFinding::query()->where('audit_id', $audit->id)->lockForUpdate()->findOrFail($finding->id);
            abort_unless($locked->accountable_owner_id === $actor->id || $actor->isSuperAdmin(), 403);
            $this->assertMutable($audit);
            $validated = Validator::make($data, self::responseRules())->validate();
            $prior = $locked->responses()->orderBy('version')->lockForUpdate()->get();
            if ($prior->count() >= 20) {
                throw ValidationException::withMessages(['finding' => 'Management-response history is bounded to 20 versions per finding.']);
            }
            $position = AuditManagementPosition::from($validated['position']);
            if ($position !== AuditManagementPosition::Disagreed && (blank($validated['action_plan'] ?? null) || empty($validated['target_date']))) {
                throw ValidationException::withMessages(['action_plan' => 'Agreed and partially agreed responses require an action plan and target date.']);
            }
            if (! empty($validated['target_date']) && $validated['target_date'] < now()->toDateString()) {
                throw ValidationException::withMessages(['target_date' => 'The management target date cannot be in the past.']);
            }
            $respondedAt = now();
            $snapshot = $locked->only(['id', 'audit_id', 'audit_item_id', 'code', 'title', 'severity', 'condition', 'criteria', 'cause', 'effect', 'recommendation', 'accountable_owner_id', 'source_snapshot', 'raised_by', 'raised_at', 'fingerprint']);
            $payload = $validated + [
                'audit_finding_id' => $locked->id, 'version' => ((int) $prior->max('version')) + 1,
                'finding_snapshot' => $snapshot, 'responded_by' => $actor->id, 'responded_at' => $respondedAt->toIso8601String(),
            ];

            $response = AuditManagementResponse::query()->create($payload + [
                'responded_at' => $respondedAt, 'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            ]);
            $this->assertAggregateBound($audit);

            return $response->load(['respondent:id,name', 'finding.accountableOwner:id,name']);
        }, 3);
    }

    public static function findingRules(): array
    {
        return [
            'audit_id' => ['prohibited'], 'code' => ['prohibited'], 'source_snapshot' => ['prohibited'], 'raised_by' => ['prohibited'], 'raised_at' => ['prohibited'], 'fingerprint' => ['prohibited'],
            'audit_item_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:255'],
            'severity' => ['required', Rule::enum(AuditFindingSeverity::class)],
            'condition' => ['required', 'string', 'max:30000'], 'criteria' => ['required', 'string', 'max:30000'],
            'cause' => ['nullable', 'string', 'max:30000'], 'effect' => ['required', 'string', 'max:30000'],
            'recommendation' => ['required', 'string', 'max:30000'], 'accountable_owner_id' => ['required', 'integer'],
        ];
    }

    public static function responseRules(): array
    {
        return [
            'audit_finding_id' => ['prohibited'], 'version' => ['prohibited'], 'finding_snapshot' => ['prohibited'], 'responded_by' => ['prohibited'], 'responded_at' => ['prohibited'], 'fingerprint' => ['prohibited'],
            'position' => ['required', Rule::enum(AuditManagementPosition::class)], 'response' => ['required', 'string', 'max:30000'],
            'action_plan' => ['nullable', 'string', 'max:30000'], 'target_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    private function authorizeManage(Audit $audit, User $actor): void
    {
        abort_unless($audit->manager_id === $actor->id || $actor->can('Update Audits'), 403);
    }

    private function assertAggregateBound(Audit $audit): void
    {
        $evidence = $audit->governedFindings()->with(['responses' => fn ($query) => $query->orderBy('version')])->orderBy('id')->get()->toArray();
        if (strlen(json_encode($evidence, JSON_THROW_ON_ERROR)) > self::MAX_EVIDENCE_BYTES) {
            throw ValidationException::withMessages(['audit_findings' => 'Governed finding and management-response evidence is bounded to 5,000,000 serialized bytes per audit.']);
        }
    }

    private function assertMutable(Audit $audit): void
    {
        if ($audit->status !== WorkflowStatus::INPROGRESS || AuditCloseoutSubmission::freezesAudit($audit->id)) {
            throw ValidationException::withMessages(['audit' => 'Findings and management responses can change only while the audit is in progress and not submitted for closeout.']);
        }
    }
}
