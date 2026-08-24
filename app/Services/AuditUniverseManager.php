<?php

namespace App\Services;

use App\Enums\AuditableEntityCriticality;
use App\Enums\AuditableEntityStatus;
use App\Enums\AuditableEntityType;
use App\Enums\AuditPlanItemStatus;
use App\Enums\AuditPlanStatus;
use App\Enums\RiskReviewFrequency;
use App\Models\Audit;
use App\Models\AuditableEntity;
use App\Models\AuditableEntityAssessment;
use App\Models\AuditPlan;
use App\Models\AuditPlanItem;
use App\Models\Control;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuditUniverseManager
{
    public function entities(User $actor): Builder
    {
        $query = AuditableEntity::query()->with([
            'owner:id,name', 'risks', 'controls', 'latestAssessment.assessor:id,name',
        ])->withCount(['risks', 'controls', 'planItems']);
        if (! $actor->can('Read Programs') && ! $actor->can('Update Programs')) {
            $query->where('owner_id', $actor->id);
        }

        return $query->latest();
    }

    public function assessmentHistory(AuditableEntity $entity, User $actor): Builder
    {
        $this->authorizeEntity($entity, $actor, false);

        return AuditableEntityAssessment::query()->where('auditable_entity_id', $entity->id)
            ->with('assessor:id,name')->latest('version');
    }

    public function plans(User $actor): Builder
    {
        $query = AuditPlan::query()->with(['manager:id,name', 'approver:id,name'])->withCount('items');
        if (! $actor->can('Read Programs') && ! $actor->can('Update Programs')) {
            $query->where('manager_id', $actor->id);
        }

        return $query->latest('plan_year')->latest('id');
    }

    public function planItems(AuditPlan $plan, User $actor): Builder
    {
        $this->authorizePlan($plan, $actor, false);

        return AuditPlanItem::query()->where('audit_plan_id', $plan->id)->with([
            'auditableEntity:id,code,name', 'assessment:id,auditable_entity_id,version,residual_score,priority_band,governance_fingerprint',
            'audit:id,title,status', 'creator:id,name',
        ])->orderByDesc('priority_rank')->orderBy('id');
    }

    public function createEntity(User $actor, array $data): AuditableEntity
    {
        $this->authorizeManager($actor);
        $validated = Validator::make($data, self::entityRules())->validate();

        return DB::transaction(function () use ($actor, $validated): AuditableEntity {
            $owner = User::query()->lockForUpdate()->findOrFail($validated['owner_id']);
            $risks = Risk::query()->whereKey($validated['risk_ids'] ?? [])->orderBy('id')->lockForUpdate()->get();
            $controls = Control::query()->whereKey($validated['control_ids'] ?? [])->orderBy('id')->lockForUpdate()->get();
            $this->assertMappingCounts($validated, $risks->count(), $controls->count());
            $entity = AuditableEntity::query()->create(collect($validated)->except(['risk_ids', 'control_ids'])->all() + [
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $entity->risks()->sync($risks->modelKeys());
            $entity->controls()->sync($controls->modelKeys());

            return $entity->load(['owner:id,name', 'risks', 'controls']);
        }, 3);
    }

    public function updateEntity(AuditableEntity $entity, User $actor, array $data): AuditableEntity
    {
        return DB::transaction(function () use ($entity, $actor, $data): AuditableEntity {
            $locked = AuditableEntity::query()->lockForUpdate()->findOrFail($entity->id);
            $this->authorizeManager($actor);
            $validated = Validator::make($data, self::entityRules($locked))->validate();
            if ($locked->assessments()->exists() && $validated['next_assessment_at'] !== $locked->next_assessment_at->toDateString()) {
                throw ValidationException::withMessages(['next_assessment_at' => 'The next assessment date is owned by assessment history. Record a new assessment to change it.']);
            }
            User::query()->lockForUpdate()->findOrFail($validated['owner_id']);
            $risks = Risk::query()->whereKey($validated['risk_ids'] ?? [])->orderBy('id')->lockForUpdate()->get();
            $controls = Control::query()->whereKey($validated['control_ids'] ?? [])->orderBy('id')->lockForUpdate()->get();
            $this->assertMappingCounts($validated, $risks->count(), $controls->count());
            $locked->update(collect($validated)->except(['risk_ids', 'control_ids'])->all() + ['updated_by' => $actor->id]);
            $locked->risks()->sync($risks->modelKeys());
            $locked->controls()->sync($controls->modelKeys());

            return $locked->load(['owner:id,name', 'risks', 'controls']);
        }, 3);
    }

    public function assess(AuditableEntity $entity, User $actor, array $data): AuditableEntityAssessment
    {
        return DB::transaction(function () use ($entity, $actor, $data): AuditableEntityAssessment {
            $locked = AuditableEntity::query()->lockForUpdate()->findOrFail($entity->id);
            $this->authorizeAssessment($locked, $actor);
            if ($locked->status !== AuditableEntityStatus::Active) {
                throw ValidationException::withMessages(['entity' => 'Only active auditable entities can be assessed.']);
            }
            $validated = Validator::make($data, self::assessmentRules())->validate();
            DB::table('auditable_entity_risk')->where('auditable_entity_id', $locked->id)->orderBy('risk_id')->lockForUpdate()->get();
            DB::table('auditable_entity_control')->where('auditable_entity_id', $locked->id)->orderBy('control_id')->lockForUpdate()->get();
            $risks = $locked->risks()->orderBy('risks.id')->lockForUpdate()->get();
            $controls = $locked->controls()->orderBy('controls.id')->lockForUpdate()->get();
            if ($risks->isEmpty()) {
                throw ValidationException::withMessages(['risk_ids' => 'A risk-based assessment requires at least one mapped risk.']);
            }
            $inherentScore = $validated['inherent_likelihood'] * $validated['inherent_impact'];
            $residualScore = $validated['residual_likelihood'] * $validated['residual_impact'];
            if ($residualScore > $inherentScore) {
                throw ValidationException::withMessages(['residual_likelihood' => 'Residual exposure cannot exceed inherent exposure.']);
            }
            $priorityBand = $this->priorityBand($locked->criticality, $residualScore);
            $entitySnapshot = $locked->only(['id', 'code', 'name', 'description', 'entity_type', 'owner_id', 'criticality', 'status', 'assessment_frequency']);
            $riskSnapshots = $risks->map(fn (Risk $risk): array => $risk->only(['id', 'code', 'name', 'domain', 'status', 'inherent_risk', 'residual_risk', 'is_active', 'updated_at']))->all();
            $controlSnapshots = $controls->map(fn (Control $control): array => $control->only(['id', 'standard_id', 'control_owner_id', 'code', 'title', 'status', 'effectiveness', 'applicability', 'updated_at']))->all();
            $version = ((int) $locked->assessments()->max('version')) + 1;
            $payload = compact('entitySnapshot', 'riskSnapshots', 'controlSnapshots', 'version', 'inherentScore', 'residualScore', 'priorityBand') + $validated;
            $assessment = $locked->assessments()->create([
                'version' => $version, 'inherent_likelihood' => $validated['inherent_likelihood'], 'inherent_impact' => $validated['inherent_impact'],
                'inherent_score' => $inherentScore, 'residual_likelihood' => $validated['residual_likelihood'], 'residual_impact' => $validated['residual_impact'],
                'residual_score' => $residualScore, 'priority_band' => $priorityBand, 'rationale' => $validated['rationale'],
                'entity_snapshot' => $entitySnapshot, 'risk_snapshots' => $riskSnapshots, 'control_snapshots' => $controlSnapshots,
                'governance_fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'next_assessment_at' => $validated['next_assessment_at'], 'assessed_by' => $actor->id, 'assessed_at' => now(),
            ]);
            $locked->update(['next_assessment_at' => $validated['next_assessment_at'], 'updated_by' => $actor->id]);

            return $assessment->load('assessor:id,name');
        }, 3);
    }

    public function createPlan(User $actor, array $data): AuditPlan
    {
        $this->authorizeManager($actor);
        $validated = Validator::make($data, self::planRules())->validate();

        return DB::transaction(function () use ($actor, $validated): AuditPlan {
            User::query()->lockForUpdate()->findOrFail($validated['manager_id']);

            return AuditPlan::query()->create($validated + ['status' => AuditPlanStatus::Draft, 'created_by' => $actor->id]);
        }, 3);
    }

    public function addPlanItem(AuditPlan $plan, User $actor, array $data): AuditPlanItem
    {
        return DB::transaction(function () use ($plan, $actor, $data): AuditPlanItem {
            $lockedPlan = AuditPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->authorizePlan($lockedPlan, $actor, true);
            if ($lockedPlan->status !== AuditPlanStatus::Draft) {
                throw ValidationException::withMessages(['plan' => 'Items can be added only to a draft audit plan.']);
            }
            $validated = Validator::make($data, self::planItemRules())->validate();
            $entity = AuditableEntity::query()->lockForUpdate()->findOrFail($validated['auditable_entity_id']);
            $assessment = AuditableEntityAssessment::query()->lockForUpdate()->findOrFail($validated['auditable_entity_assessment_id']);
            if ($assessment->auditable_entity_id !== $entity->id || $entity->latestAssessment()->value('id') !== $assessment->id) {
                throw ValidationException::withMessages(['auditable_entity_assessment_id' => 'Planning requires the current assessment for the selected entity.']);
            }
            DB::table('auditable_entity_risk')->where('auditable_entity_id', $entity->id)->orderBy('risk_id')->lockForUpdate()->get();
            DB::table('auditable_entity_control')->where('auditable_entity_id', $entity->id)->orderBy('control_id')->lockForUpdate()->get();
            $entity->setRelation('risks', $entity->risks()->orderBy('risks.id')->lockForUpdate()->get());
            $entity->setRelation('controls', $entity->controls()->orderBy('controls.id')->lockForUpdate()->get());
            if (! $entity->assessmentIsCurrent($assessment)) {
                throw ValidationException::withMessages(['auditable_entity_assessment_id' => 'The entity or its mapped governance context changed and requires reassessment before planning.']);
            }
            if ((int) Carbon::parse($validated['planned_start_at'])->year !== $lockedPlan->plan_year || (int) Carbon::parse($validated['planned_end_at'])->year !== $lockedPlan->plan_year) {
                throw ValidationException::withMessages(['planned_start_at' => 'Planned dates must fall within the audit plan year.']);
            }
            $audit = isset($validated['audit_id']) ? Audit::query()->lockForUpdate()->findOrFail($validated['audit_id']) : null;
            if (AuditPlanItemStatus::from($validated['status']) === AuditPlanItemStatus::Scheduled && ! $audit) {
                throw ValidationException::withMessages(['audit_id' => 'A scheduled plan item requires a linked audit.']);
            }
            $snapshot = ['entity' => $assessment->entity_snapshot, 'assessment' => $assessment->only(['id', 'version', 'inherent_score', 'residual_score', 'priority_band', 'rationale', 'risk_snapshots', 'control_snapshots', 'governance_fingerprint', 'next_assessment_at', 'assessed_by', 'assessed_at'])];

            return $lockedPlan->items()->create([
                'auditable_entity_id' => $entity->id, 'auditable_entity_assessment_id' => $assessment->id,
                'audit_id' => $audit?->id, 'status' => $validated['status'], 'planned_start_at' => $validated['planned_start_at'],
                'planned_end_at' => $validated['planned_end_at'], 'rationale' => $validated['rationale'],
                'entity_assessment_snapshot' => $snapshot, 'priority_rank' => $this->priorityRank($assessment), 'created_by' => $actor->id,
            ]);
        }, 3);
    }

    public function approvePlan(AuditPlan $plan, User $actor): AuditPlan
    {
        return DB::transaction(function () use ($plan, $actor): AuditPlan {
            $locked = AuditPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->authorizePlan($locked, $actor, true);
            if ($locked->status !== AuditPlanStatus::Draft) {
                throw ValidationException::withMessages(['plan' => 'Only a draft audit plan can be approved.']);
            }
            $items = $locked->items()->orderByDesc('priority_rank')->orderBy('id')->lockForUpdate()->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'An audit plan requires at least one prioritized entity.']);
            }
            $entities = AuditableEntity::query()->whereKey($items->pluck('auditable_entity_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $assessments = AuditableEntityAssessment::query()->whereKey($items->pluck('auditable_entity_assessment_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $audits = Audit::query()->whereKey($items->pluck('audit_id')->filter()->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($items as $item) {
                $entity = $entities->get($item->auditable_entity_id);
                $assessment = $assessments->get($item->auditable_entity_assessment_id);
                if (! $entity || ! $assessment || $assessment->auditable_entity_id !== $entity->id || $entity->latestAssessment()->value('id') !== $assessment->id) {
                    throw ValidationException::withMessages(['items' => 'Every plan item must reference the current assessment for its entity.']);
                }
                DB::table('auditable_entity_risk')->where('auditable_entity_id', $entity->id)->orderBy('risk_id')->lockForUpdate()->get();
                DB::table('auditable_entity_control')->where('auditable_entity_id', $entity->id)->orderBy('control_id')->lockForUpdate()->get();
                $entity->setRelation('risks', $entity->risks()->orderBy('risks.id')->lockForUpdate()->get());
                $entity->setRelation('controls', $entity->controls()->orderBy('controls.id')->lockForUpdate()->get());
                if (! $entity->assessmentIsCurrent($assessment)) {
                    throw ValidationException::withMessages(['items' => 'An entity or mapped governance context changed after planning and requires reassessment.']);
                }
                if ($item->status === AuditPlanItemStatus::Scheduled && (! $item->audit_id || ! $audits->has($item->audit_id))) {
                    throw ValidationException::withMessages(['items' => 'Every scheduled plan item must retain a linked audit.']);
                }
            }
            $snapshot = [
                'plan' => $locked->only(['id', 'plan_year', 'name', 'objective', 'manager_id']),
                'items' => $items->map(fn (AuditPlanItem $item): array => $item->only(['id', 'auditable_entity_id', 'auditable_entity_assessment_id', 'audit_id', 'status', 'planned_start_at', 'planned_end_at', 'rationale', 'entity_assessment_snapshot', 'priority_rank', 'created_by']))->all(),
            ];
            $locked->update([
                'status' => AuditPlanStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now(),
                'approval_snapshot' => $snapshot, 'approval_fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            ]);

            return $locked->load(['manager:id,name', 'approver:id,name', 'items']);
        }, 3);
    }

    public function updatePlanItem(AuditPlan $plan, AuditPlanItem $item, User $actor, array $data): AuditPlanItem
    {
        return DB::transaction(function () use ($plan, $item, $actor, $data): AuditPlanItem {
            $lockedPlan = AuditPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->authorizePlan($lockedPlan, $actor, true);
            if ($lockedPlan->status !== AuditPlanStatus::Draft) {
                throw ValidationException::withMessages(['plan' => 'Only draft plan items can be corrected.']);
            }
            $lockedItem = AuditPlanItem::query()->where('audit_plan_id', $lockedPlan->id)->lockForUpdate()->findOrFail($item->id);
            $validated = Validator::make($data, self::draftPlanItemRules())->validate();
            if ((int) Carbon::parse($validated['planned_start_at'])->year !== $lockedPlan->plan_year || (int) Carbon::parse($validated['planned_end_at'])->year !== $lockedPlan->plan_year) {
                throw ValidationException::withMessages(['planned_start_at' => 'Planned dates must fall within the audit plan year.']);
            }
            $audit = isset($validated['audit_id']) ? Audit::query()->lockForUpdate()->findOrFail($validated['audit_id']) : null;
            if (AuditPlanItemStatus::from($validated['status']) === AuditPlanItemStatus::Scheduled && ! $audit) {
                throw ValidationException::withMessages(['audit_id' => 'A scheduled plan item requires a linked audit.']);
            }
            $lockedItem->update($validated);

            return $lockedItem->load(['auditableEntity:id,code,name', 'assessment', 'audit:id,title,status']);
        }, 3);
    }

    public function removePlanItem(AuditPlan $plan, AuditPlanItem $item, User $actor): void
    {
        DB::transaction(function () use ($plan, $item, $actor): void {
            $lockedPlan = AuditPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->authorizePlan($lockedPlan, $actor, true);
            if ($lockedPlan->status !== AuditPlanStatus::Draft) {
                throw ValidationException::withMessages(['plan' => 'Only draft plan items can be removed.']);
            }
            AuditPlanItem::query()->where('audit_plan_id', $lockedPlan->id)->lockForUpdate()->findOrFail($item->id)->delete();
        }, 3);
    }

    public static function entityRules(?AuditableEntity $entity = null): array
    {
        return [
            'code' => ['required', 'string', 'max:255', Rule::unique('auditable_entities')->ignore($entity)], 'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:30000'], 'entity_type' => ['required', Rule::enum(AuditableEntityType::class)],
            'owner_id' => ['required', 'integer', 'exists:users,id'], 'criticality' => ['required', Rule::enum(AuditableEntityCriticality::class)],
            'status' => ['required', Rule::enum(AuditableEntityStatus::class)], 'assessment_frequency' => ['required', Rule::enum(RiskReviewFrequency::class)],
            'next_assessment_at' => ['required', 'date_format:Y-m-d'], 'risk_ids' => ['required', 'array', 'min:1', 'max:100'],
            'risk_ids.*' => ['integer', 'distinct', 'exists:risks,id'], 'control_ids' => ['sometimes', 'array', 'max:250'],
            'control_ids.*' => ['integer', 'distinct', 'exists:controls,id'],
        ];
    }

    public static function assessmentRules(): array
    {
        return [
            'inherent_likelihood' => ['required', 'integer', 'between:1,5'], 'inherent_impact' => ['required', 'integer', 'between:1,5'],
            'residual_likelihood' => ['required', 'integer', 'between:1,5'], 'residual_impact' => ['required', 'integer', 'between:1,5'],
            'rationale' => ['required', 'string', 'max:30000'], 'next_assessment_at' => ['required', 'date_format:Y-m-d', 'after:today'],
        ];
    }

    public static function planRules(): array
    {
        return ['plan_year' => ['required', 'integer', 'between:2000,2200'], 'name' => ['required', 'string', 'max:255'], 'objective' => ['required', 'string', 'max:30000'], 'manager_id' => ['required', 'integer', 'exists:users,id']];
    }

    public static function planItemRules(): array
    {
        return [
            'auditable_entity_id' => ['required', 'integer', 'exists:auditable_entities,id'],
            'auditable_entity_assessment_id' => ['required', 'integer', 'exists:auditable_entity_assessments,id'],
            'audit_id' => ['nullable', 'integer', 'exists:audits,id'], 'status' => ['required', Rule::enum(AuditPlanItemStatus::class)],
            'planned_start_at' => ['required', 'date_format:Y-m-d'], 'planned_end_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:planned_start_at'],
            'rationale' => ['required', 'string', 'max:30000'],
        ];
    }

    public static function draftPlanItemRules(): array
    {
        return [
            'audit_id' => ['nullable', 'integer', 'exists:audits,id'], 'status' => ['required', Rule::enum(AuditPlanItemStatus::class)],
            'planned_start_at' => ['required', 'date_format:Y-m-d'], 'planned_end_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:planned_start_at'],
            'rationale' => ['required', 'string', 'max:30000'],
        ];
    }

    private function authorizeManager(User $actor): void
    {
        if (! $actor->can('Update Programs')) {
            abort(403, 'You cannot manage the audit universe.');
        }
    }

    private function authorizeEntity(AuditableEntity $entity, User $actor, bool $write): void
    {
        if (($write && $actor->can('Update Programs')) || (! $write && $actor->can('Read Programs')) || $entity->owner_id === $actor->id) {
            return;
        }
        abort(403, 'You cannot access this auditable entity.');
    }

    private function authorizeAssessment(AuditableEntity $entity, User $actor): void
    {
        if ($actor->can('Update Programs') || $entity->owner_id === $actor->id) {
            return;
        }
        abort(403, 'You cannot assess this auditable entity.');
    }

    private function authorizePlan(AuditPlan $plan, User $actor, bool $write): void
    {
        if ($actor->can('Update Programs') || (! $write && $actor->can('Read Programs')) || $plan->manager_id === $actor->id) {
            return;
        }
        abort(403, 'You cannot manage this audit plan.');
    }

    private function assertMappingCounts(array $validated, int $riskCount, int $controlCount): void
    {
        if ($riskCount !== count($validated['risk_ids'] ?? []) || $controlCount !== count($validated['control_ids'] ?? [])) {
            throw ValidationException::withMessages(['mappings' => 'Every mapped risk and control must remain active and available.']);
        }
    }

    private function priorityBand(AuditableEntityCriticality $criticality, int $residualScore): string
    {
        if ($residualScore >= 20 || ($criticality === AuditableEntityCriticality::Critical && $residualScore >= 15)) {
            return 'critical';
        }
        if ($residualScore >= 15 || $criticality === AuditableEntityCriticality::Critical) {
            return 'high';
        }
        if ($residualScore >= 8 || $criticality === AuditableEntityCriticality::High) {
            return 'medium';
        }

        return 'low';
    }

    private function priorityRank(AuditableEntityAssessment $assessment): int
    {
        $band = match ($assessment->priority_band) {
            'critical' => 4, 'high' => 3, 'medium' => 2, default => 1
        };

        return ($band * 100) + $assessment->residual_score;
    }
}
