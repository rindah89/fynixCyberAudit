<?php

namespace App\Services;

use App\Enums\AuditPlanItemStatus;
use App\Enums\AuditPlanStatus;
use App\Enums\WorkflowStatus;
use App\Models\Audit;
use App\Models\AuditEngagementBaseline;
use App\Models\AuditPlan;
use App\Models\AuditPlanItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuditEngagementManager
{
    public function launch(AuditPlanItem $item, User $actor, array $data): AuditEngagementBaseline
    {
        return DB::transaction(function () use ($item, $actor, $data): AuditEngagementBaseline {
            $planId = AuditPlanItem::query()->findOrFail($item->id)->audit_plan_id;
            $plan = AuditPlan::query()->lockForUpdate()->findOrFail($planId);
            $lockedItem = AuditPlanItem::query()->where('audit_plan_id', $plan->id)->lockForUpdate()->findOrFail($item->id);
            $this->authorizeLaunch($plan, $actor);
            if ($plan->status !== AuditPlanStatus::Approved) {
                throw ValidationException::withMessages(['plan' => 'Only an approved audit plan item can launch an engagement.']);
            }
            if ($lockedItem->status !== AuditPlanItemStatus::Planned) {
                throw ValidationException::withMessages(['item' => 'Only a planned item without an audit can launch an engagement.']);
            }
            if ($lockedItem->audit_id || $lockedItem->engagementBaseline()->exists()) {
                throw ValidationException::withMessages(['item' => 'This plan item already has an audit engagement.']);
            }

            $validated = Validator::make($data, self::rules())->validate();
            $manager = User::query()->lockForUpdate()->findOrFail($validated['manager_id']);
            $teamIds = collect($validated['team_user_ids'] ?? [])->push($manager->id)->unique()->sort()->values();
            $team = User::query()->whereKey($teamIds)->orderBy('id')->lockForUpdate()->get();
            if ($team->count() !== $teamIds->count()) {
                throw ValidationException::withMessages(['team_user_ids' => 'Every engagement team member must be an active user.']);
            }

            $audit = Audit::query()->create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'audit_type' => $validated['audit_type'],
                'status' => WorkflowStatus::NOTSTARTED,
                'start_date' => $lockedItem->planned_start_at,
                'end_date' => $lockedItem->planned_end_at,
                'manager_id' => $manager->id,
                'program_id' => $validated['program_id'] ?? null,
            ]);
            $audit->members()->sync($team->modelKeys());

            $auditSnapshot = [
                'id' => $audit->id,
                'title' => $audit->title,
                'description' => $audit->description,
                'audit_type' => $audit->audit_type,
                'status' => $audit->status->value,
                'start_date' => $audit->start_date->toDateString(),
                'end_date' => $audit->end_date->toDateString(),
                'manager_id' => $audit->manager_id,
                'program_id' => $audit->program_id,
            ];

            $planSnapshot = [
                'plan' => $plan->only(['id', 'plan_year', 'name', 'objective', 'manager_id', 'approved_by', 'approved_at', 'approval_fingerprint']),
                'item' => $lockedItem->only(['id', 'auditable_entity_id', 'auditable_entity_assessment_id', 'status', 'planned_start_at', 'planned_end_at', 'rationale', 'priority_rank']),
            ];
            $launchedAt = now();
            $payload = [
                'audit_snapshot' => $auditSnapshot,
                'objective' => $validated['objective'],
                'scope' => $validated['scope'],
                'exclusions' => $validated['exclusions'] ?? null,
                'team_user_ids' => $team->modelKeys(),
                'plan_snapshot' => $planSnapshot,
                'entity_assessment_snapshot' => $lockedItem->entity_assessment_snapshot,
                'launched_by' => $actor->id,
                'launched_at' => $launchedAt->toIso8601String(),
            ];

            return AuditEngagementBaseline::query()->create($payload + [
                'audit_id' => $audit->id,
                'audit_plan_item_id' => $lockedItem->id,
                'launched_at' => $launchedAt,
                'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            ])->load(['audit.manager:id,name', 'planItem.plan', 'launcher:id,name']);
        }, 3);
    }

    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:30000'],
            'audit_type' => ['required', 'string', 'in:controls,implementations'],
            'manager_id' => ['required', 'integer', 'exists:users,id'],
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'objective' => ['required', 'string', 'max:30000'],
            'scope' => ['required', 'string', 'max:30000'],
            'exclusions' => ['nullable', 'string', 'max:30000'],
            'team_user_ids' => ['sometimes', 'array', 'max:100'],
            'team_user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }

    private function authorizeLaunch(AuditPlan $plan, User $actor): void
    {
        if ($actor->can('Create Audits') && ($actor->can('Update Programs') || $plan->manager_id === $actor->id)) {
            return;
        }

        abort(403, 'You cannot launch an audit engagement from this plan.');
    }
}
