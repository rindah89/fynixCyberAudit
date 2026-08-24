<?php

namespace App\Services;

use App\Enums\AuditTimeEntryType;
use App\Enums\WorkflowStatus;
use App\Models\Audit;
use App\Models\AuditCloseoutSubmission;
use App\Models\AuditEffortBudget;
use App\Models\AuditProcedure;
use App\Models\AuditTimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuditEffortManager
{
    public function budget(Audit $audit, User $actor, array $data): AuditEffortBudget
    {
        return DB::transaction(function () use ($audit, $actor, $data): AuditEffortBudget {
            $locked = Audit::query()->lockForUpdate()->findOrFail($audit->id);
            $this->authorizeManage($locked, $actor);
            $this->assertMutable($locked);
            $validated = Validator::make($data, self::budgetRules())->validate();
            if ($locked->effortBudgets()->count() >= 2000) {
                throw ValidationException::withMessages(['audit' => 'Audit effort history is bounded to 2,000 budget versions.']);
            }
            DB::table('audit_user')->where('audit_id', $locked->id)->orderBy('user_id')->lockForUpdate()->get();
            $user = User::query()->lockForUpdate()->findOrFail($validated['user_id']);
            if ($user->id !== $locked->manager_id && ! $locked->members()->whereKey($user->id)->exists()) {
                throw ValidationException::withMessages(['user_id' => 'Effort can be budgeted only for the audit manager or a current audit member.']);
            }
            $procedure = $this->lockProcedure($locked, $validated['audit_procedure_id'] ?? null);
            if ($procedure && $procedure->assigned_to !== $user->id) {
                throw ValidationException::withMessages(['user_id' => 'A procedure budget must be allocated to its assigned auditor.']);
            }
            $prior = AuditEffortBudget::query()->where('audit_id', $locked->id)->where('user_id', $user->id)
                ->where('audit_procedure_id', $procedure?->id)->orderBy('version')->lockForUpdate()->get();
            $setAt = now();
            $snapshot = $this->allocationSnapshot($locked, $procedure, $user);
            $payload = [
                'audit_id' => $locked->id, 'audit_procedure_id' => $procedure?->id, 'user_id' => $user->id,
                'version' => ((int) $prior->max('version')) + 1, 'planned_minutes' => $validated['planned_minutes'],
                'rationale' => $validated['rationale'], 'allocation_snapshot' => $snapshot,
                'set_by' => $actor->id, 'set_at' => $setAt->toIso8601String(),
            ];

            return AuditEffortBudget::query()->create($payload + [
                'set_at' => $setAt, 'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            ])->load(['procedure:id,code,title,assigned_to', 'user:id,name', 'setter:id,name']);
        }, 3);
    }

    public function record(Audit $audit, User $actor, array $data): AuditTimeEntry
    {
        return DB::transaction(function () use ($audit, $actor, $data): AuditTimeEntry {
            $locked = Audit::query()->lockForUpdate()->findOrFail($audit->id);
            $this->authorizeTeamMember($locked, $actor);
            $this->assertMutable($locked);
            $validated = Validator::make($data, self::entryRules())->validate();
            if ($locked->timeEntries()->count() >= 10000) {
                throw ValidationException::withMessages(['audit' => 'Audit time history is bounded to 10,000 entries including reversals.']);
            }
            $this->assertWorkDate($locked, $validated['work_date']);
            $procedure = $this->lockProcedure($locked, $validated['audit_procedure_id'] ?? null);
            if ($procedure && $procedure->assigned_to !== $actor->id) {
                throw ValidationException::withMessages(['audit_procedure_id' => 'Time can be recorded only by the auditor assigned to this procedure.']);
            }
            $entries = AuditTimeEntry::query()->where('audit_id', $locked->id)->where('user_id', $actor->id)
                ->whereDate('work_date', $validated['work_date'])->orderBy('id')->lockForUpdate()->get();
            $activeMinutes = $entries->where('entry_type', AuditTimeEntryType::Work)
                ->reject(fn (AuditTimeEntry $entry): bool => $entries->contains('reverses_time_entry_id', $entry->id))->sum('minutes');
            if ($activeMinutes + $validated['minutes'] > 1440) {
                throw ValidationException::withMessages(['minutes' => 'Active time for one user cannot exceed 1,440 minutes on a work date.']);
            }
            $enteredAt = now();
            $budget = $this->currentBudget($locked->id, $actor->id, $procedure?->id);
            $payload = [
                'audit_id' => $locked->id, 'audit_procedure_id' => $procedure?->id, 'user_id' => $actor->id,
                'entry_type' => AuditTimeEntryType::Work->value, 'reverses_time_entry_id' => null,
                'work_date' => $validated['work_date'], 'minutes' => $validated['minutes'], 'activity' => $validated['activity'],
                'notes' => $validated['notes'] ?? null, 'source_reference' => $validated['source_reference'] ?? null,
                'budget_snapshot' => $budget?->only(['id', 'version', 'planned_minutes', 'rationale', 'allocation_snapshot', 'fingerprint']),
                'procedure_snapshot' => $procedure ? $this->procedureSnapshot($procedure) : null,
                'entered_by' => $actor->id, 'entered_at' => $enteredAt->toIso8601String(),
            ];

            return AuditTimeEntry::query()->create($payload + [
                'entered_at' => $enteredAt, 'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            ])->load(['procedure:id,code,title', 'user:id,name', 'entrant:id,name']);
        }, 3);
    }

    public function reverse(AuditTimeEntry $entry, User $actor, array $data): AuditTimeEntry
    {
        return DB::transaction(function () use ($entry, $actor, $data): AuditTimeEntry {
            $auditId = AuditTimeEntry::query()->findOrFail($entry->id)->audit_id;
            $audit = Audit::query()->lockForUpdate()->findOrFail($auditId);
            $locked = AuditTimeEntry::query()->where('audit_id', $audit->id)->lockForUpdate()->findOrFail($entry->id);
            abort_unless($locked->user_id === $actor->id || $audit->manager_id === $actor->id || $actor->can('Update Audits'), 403);
            $this->assertMutable($audit);
            if ($audit->timeEntries()->count() >= 10000) {
                throw ValidationException::withMessages(['audit' => 'Audit time history is bounded to 10,000 entries including reversals.']);
            }
            if ($locked->entry_type !== AuditTimeEntryType::Work || $locked->reversal()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['entry' => 'Only an unreversed work entry can be reversed once.']);
            }
            $validated = Validator::make($data, self::reversalRules())->validate();
            $enteredAt = now();
            $payload = [
                'audit_id' => $audit->id, 'audit_procedure_id' => $locked->audit_procedure_id, 'user_id' => $locked->user_id,
                'entry_type' => AuditTimeEntryType::Reversal->value, 'reverses_time_entry_id' => $locked->id,
                'work_date' => $locked->work_date->toDateString(), 'minutes' => $locked->minutes,
                'activity' => 'Reversal: '.$validated['reason'], 'notes' => $validated['notes'] ?? null,
                'source_reference' => null, 'budget_snapshot' => $locked->budget_snapshot,
                'procedure_snapshot' => $locked->procedure_snapshot, 'entered_by' => $actor->id,
                'entered_at' => $enteredAt->toIso8601String(),
            ];

            return AuditTimeEntry::query()->create($payload + [
                'entered_at' => $enteredAt, 'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            ])->load(['reversedEntry', 'user:id,name', 'entrant:id,name']);
        }, 3);
    }

    public function summary(Audit $audit): array
    {
        $budgets = $audit->effortBudgets()->orderBy('version')->get();
        $latest = $budgets->groupBy(fn (AuditEffortBudget $budget): string => ($budget->audit_procedure_id ?? 'audit').':'.$budget->user_id)->map->last()->values();
        $entries = $audit->timeEntries()->orderBy('id')->get();
        $reversedIds = $entries->where('entry_type', AuditTimeEntryType::Reversal)->pluck('reverses_time_entry_id')->filter();
        $active = $entries->where('entry_type', AuditTimeEntryType::Work)->whereNotIn('id', $reversedIds);
        $planned = (int) $latest->sum('planned_minutes');
        $actual = (int) $active->sum('minutes');

        $budgetByScope = $latest->keyBy(fn (AuditEffortBudget $budget): string => ($budget->audit_procedure_id ?? 'audit').':'.$budget->user_id);
        $activeByScope = $active->groupBy(fn (AuditTimeEntry $entry): string => ($entry->audit_procedure_id ?? 'audit').':'.$entry->user_id);
        $scopeKeys = $budgetByScope->keys()->merge($activeByScope->keys())->unique()->sort()->values();

        return [
            'planned_minutes' => $planned, 'actual_minutes' => $actual, 'variance_minutes' => $planned - $actual,
            'allocations' => $scopeKeys->map(function (string $key) use ($budgetByScope, $activeByScope): array {
                $budget = $budgetByScope->get($key);
                $entries = $activeByScope->get($key, collect());
                $entry = $entries->first();

                return [
                    'budget_id' => $budget?->id, 'procedure_id' => $budget?->audit_procedure_id ?? $entry?->audit_procedure_id,
                    'procedure_code' => data_get($budget?->allocation_snapshot, 'procedure.code') ?? data_get($entry?->procedure_snapshot, 'code'),
                    'user_id' => $budget?->user_id ?? $entry?->user_id,
                    'user_name' => data_get($budget?->allocation_snapshot, 'user.name') ?? data_get($entry?->budget_snapshot, 'allocation_snapshot.user.name'),
                    'version' => $budget?->version, 'planned_minutes' => $budget?->planned_minutes ?? 0,
                    'actual_minutes' => (int) $entries->sum('minutes'),
                ];
            })->all(),
        ];
    }

    public static function budgetRules(): array
    {
        return [
            'audit_id' => ['prohibited'], 'version' => ['prohibited'], 'allocation_snapshot' => ['prohibited'], 'set_by' => ['prohibited'], 'set_at' => ['prohibited'], 'fingerprint' => ['prohibited'],
            'audit_procedure_id' => ['nullable', 'integer'], 'user_id' => ['required', 'integer'],
            'planned_minutes' => ['required', 'integer', 'min:1', 'max:600000'], 'rationale' => ['required', 'string', 'max:10000'],
        ];
    }

    public static function entryRules(): array
    {
        return [
            'audit_id' => ['prohibited'], 'user_id' => ['prohibited'], 'entry_type' => ['prohibited'], 'reverses_time_entry_id' => ['prohibited'],
            'budget_snapshot' => ['prohibited'], 'procedure_snapshot' => ['prohibited'], 'entered_by' => ['prohibited'], 'entered_at' => ['prohibited'], 'fingerprint' => ['prohibited'],
            'audit_procedure_id' => ['nullable', 'integer'], 'work_date' => ['required', 'date_format:Y-m-d'],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'], 'activity' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'], 'source_reference' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public static function reversalRules(): array
    {
        return ['reason' => ['required', 'string', 'max:240'], 'notes' => ['nullable', 'string', 'max:10000']];
    }

    private function authorizeManage(Audit $audit, User $actor): void
    {
        abort_unless($audit->manager_id === $actor->id || $actor->can('Update Audits'), 403);
    }

    private function authorizeTeamMember(Audit $audit, User $actor): void
    {
        abort_unless($audit->manager_id === $actor->id || $audit->members()->whereKey($actor->id)->exists(), 403);
    }

    private function assertMutable(Audit $audit): void
    {
        if ($audit->status !== WorkflowStatus::INPROGRESS || AuditCloseoutSubmission::freezesAudit($audit->id)) {
            throw ValidationException::withMessages(['audit' => 'Effort can change only while the audit is in progress and not submitted for closeout.']);
        }
    }

    private function assertWorkDate(Audit $audit, string $date): void
    {
        if ($date < $audit->start_date->toDateString() || $date > $audit->end_date->toDateString() || $date > now()->toDateString()) {
            throw ValidationException::withMessages(['work_date' => 'The work date must fall within the audit period and cannot be in the future.']);
        }
    }

    private function lockProcedure(Audit $audit, ?int $procedureId): ?AuditProcedure
    {
        return $procedureId ? AuditProcedure::query()->where('audit_id', $audit->id)->lockForUpdate()->findOrFail($procedureId) : null;
    }

    private function allocationSnapshot(Audit $audit, ?AuditProcedure $procedure, User $user): array
    {
        return [
            'audit' => $audit->only(['id', 'title', 'status', 'start_date', 'end_date', 'manager_id']),
            'procedure' => $procedure ? $this->procedureSnapshot($procedure) : null,
            'user' => $user->only(['id', 'name', 'email']),
        ];
    }

    private function procedureSnapshot(AuditProcedure $procedure): array
    {
        return $procedure->only(['id', 'audit_id', 'audit_item_id', 'version', 'code', 'title', 'objective', 'steps', 'method', 'assigned_to', 'due_at', 'status']);
    }

    private function currentBudget(int $auditId, int $userId, ?int $procedureId): ?AuditEffortBudget
    {
        return AuditEffortBudget::query()->where('audit_id', $auditId)->where('user_id', $userId)
            ->where('audit_procedure_id', $procedureId)->latest('version')->lockForUpdate()->first()
            ?? ($procedureId ? AuditEffortBudget::query()->where('audit_id', $auditId)->where('user_id', $userId)->whereNull('audit_procedure_id')->latest('version')->lockForUpdate()->first() : null);
    }
}
