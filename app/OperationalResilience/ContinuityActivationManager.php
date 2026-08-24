<?php

namespace App\OperationalResilience;

use App\Enums\ContinuityActivationStatus;
use App\Enums\ContinuityRecoveryOutcome;
use App\Models\BusinessImpactAnalysis;
use App\Models\BusinessService;
use App\Models\ContinuityActivation;
use App\Models\ContinuityActivationEvent;
use App\Models\RecoveryPlan;
use App\Models\User;
use App\Support\Enterprise;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContinuityActivationManager
{
    public function activate(User $actor, RecoveryPlan $plan, array $data): ContinuityActivation
    {
        $this->assertCanManage($actor);
        $data = Validator::make($data, [
            'incident_id' => 'nullable|integer|exists:incidents,id', 'disruption_summary' => 'required|string|max:10000',
            'business_impact' => 'required|string|max:30000', 'started_at' => 'required|date|before_or_equal:now',
        ])->validate();

        return DB::transaction(function () use ($actor, $plan, $data): ContinuityActivation {
            $lockedPlan = RecoveryPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $this->assertCanManage($actor);
            if ($lockedPlan->status->value !== 'approved') {
                throw ValidationException::withMessages(['recovery_plan_id' => 'Only an approved recovery plan can be activated.']);
            }
            $service = BusinessService::query()->lockForUpdate()->findOrFail($lockedPlan->business_service_id);
            if ((int) $service->latestApprovedRecoveryPlan()->value('id') !== $lockedPlan->id) {
                throw ValidationException::withMessages(['recovery_plan_id' => 'Only the latest approved recovery plan can be activated.']);
            }
            if ($service->status !== 'active') {
                throw ValidationException::withMessages(['business_service_id' => 'Only an active business service can enter continuity recovery.']);
            }
            if ($service->continuityActivations()->whereNotIn('status', ['closed', 'cancelled'])->exists()) {
                throw ValidationException::withMessages(['business_service_id' => 'The service already has an active continuity recovery.']);
            }
            if ($service->continuityActivations()->count() >= 100) {
                throw ValidationException::withMessages(['business_service_id' => 'A business service is limited to 100 retained continuity activations.']);
            }
            $bia = $service->latestApprovedImpactAnalysis()->lockForUpdate()->first();
            if (! $bia) {
                throw ValidationException::withMessages(['recovery_plan_id' => 'A current approved impact analysis is required.']);
            }
            $recordedAt = now();
            $activation = ContinuityActivation::query()->create([
                ...$data, 'recovery_plan_id' => $lockedPlan->id, 'business_service_id' => $service->id,
                'activated_by' => $actor->id, 'status' => ContinuityActivationStatus::Activated,
                'service_snapshot' => $this->serviceSnapshot($service, $bia), 'plan_snapshot' => $this->planSnapshot($lockedPlan),
            ]);
            $this->appendEvent($activation, $actor, null, ContinuityActivationStatus::Activated, 'Approved recovery plan activated for the reported disruption.', $recordedAt);

            return $activation->fresh(['activator:id,name', 'events.recorder:id,name']);
        }, 3);
    }

    public function transition(User $actor, ContinuityActivation $activation, array $data): ContinuityActivationEvent
    {
        $this->assertCanManage($actor);

        return DB::transaction(function () use ($actor, $activation, $data): ContinuityActivationEvent {
            $planId = ContinuityActivation::query()->whereKey($activation->id)->value('recovery_plan_id');
            $plan = RecoveryPlan::query()->lockForUpdate()->findOrFail($planId);
            $locked = ContinuityActivation::query()->where('recovery_plan_id', $plan->id)->lockForUpdate()->findOrFail($activation->id);
            $this->assertCanManage($actor);
            $data = Validator::make($data, [
                'status' => ['required', Rule::enum(ContinuityActivationStatus::class)], 'summary' => 'required|string|max:10000',
                'actual_recovery_point_minutes' => 'required_if:status,restored|nullable|integer|min:0|max:525600',
            ])->validate();
            $next = ContinuityActivationStatus::from($data['status']);
            if (! in_array($next, $locked->status->allowedNext(), true)) {
                throw ValidationException::withMessages(['status' => 'Continuity recovery must advance through an allowed next state.']);
            }
            if ($locked->events()->count() >= 100) {
                throw ValidationException::withMessages(['continuity_activation_id' => 'A continuity activation is limited to 100 retained events.']);
            }
            $recordedAt = now();
            $updates = ['status' => $next];
            if ($next === ContinuityActivationStatus::Restored) {
                $started = Carbon::parse($locked->started_at);
                $minutes = max(0, (int) $started->diffInMinutes($recordedAt));
                $rto = (int) $locked->service_snapshot['impact_analysis']['recovery_time_objective_minutes'];
                $rpo = (int) $locked->service_snapshot['impact_analysis']['recovery_point_objective_minutes'];
                $actualRpo = (int) $data['actual_recovery_point_minutes'];
                $updates += ['restored_at' => $recordedAt, 'actual_recovery_time_minutes' => $minutes, 'actual_recovery_point_minutes' => $actualRpo,
                    'outcome' => $minutes <= $rto && $actualRpo <= $rpo ? ContinuityRecoveryOutcome::Met : (($minutes <= $rto || $actualRpo <= $rpo) ? ContinuityRecoveryOutcome::Partial : ContinuityRecoveryOutcome::Missed)];
            }
            if ($next === ContinuityActivationStatus::Closed || $next === ContinuityActivationStatus::Cancelled) {
                $updates['closed_at'] = $recordedAt;
            }
            $from = $locked->status;
            $locked->update($updates);

            return $this->appendEvent($locked->refresh(), $actor, $from, $next, $data['summary'], $recordedAt)->load('recorder:id,name');
        }, 3);
    }

    private function appendEvent(ContinuityActivation $activation, User $actor, ?ContinuityActivationStatus $from, ContinuityActivationStatus $to, string $summary, Carbon $at): ContinuityActivationEvent
    {
        $snapshot = $this->activationSnapshot($activation);
        $payload = ['continuity_activation_id' => $activation->id, 'version' => ((int) $activation->events()->max('version')) + 1,
            'from_status' => $from?->value, 'to_status' => $to->value, 'summary' => $summary, 'activation_snapshot' => $snapshot,
            'recorded_by' => $actor->id, 'recorded_at' => $at->toIso8601String()];

        return $activation->events()->create($payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))]);
    }

    private function serviceSnapshot(BusinessService $service, BusinessImpactAnalysis $bia): array
    {
        return ['id' => $service->id, 'code' => $service->code, 'name' => $service->name, 'criticality' => $service->criticality->value, 'status' => $service->status, 'owner_id' => $service->owner_id,
            'impact_analysis' => ['id' => $bia->id, 'version' => $bia->version, 'maximum_tolerable_downtime_minutes' => $bia->maximum_tolerable_downtime_minutes, 'recovery_time_objective_minutes' => $bia->recovery_time_objective_minutes, 'recovery_point_objective_minutes' => $bia->recovery_point_objective_minutes, 'approved_by' => $bia->approved_by, 'approved_at' => $bia->approved_at?->toIso8601String()]];
    }

    private function planSnapshot(RecoveryPlan $plan): array
    {
        return ['id' => $plan->id, 'version' => $plan->version, 'title' => $plan->title, 'strategy' => $plan->strategy, 'activation_criteria' => $plan->activation_criteria, 'recovery_procedure' => $plan->recovery_procedure, 'communication_plan' => $plan->communication_plan, 'owner_id' => $plan->owner_id, 'approved_by' => $plan->approved_by, 'approved_at' => $plan->approved_at?->toIso8601String(), 'review_due_at' => $plan->review_due_at?->format('Y-m-d')];
    }

    private function activationSnapshot(ContinuityActivation $a): array
    {
        return ['id' => $a->id, 'recovery_plan_id' => $a->recovery_plan_id, 'business_service_id' => $a->business_service_id, 'incident_id' => $a->incident_id, 'activated_by' => $a->activated_by, 'status' => $a->status->value, 'disruption_summary' => $a->disruption_summary, 'business_impact' => $a->business_impact, 'started_at' => $a->started_at->toIso8601String(), 'restored_at' => $a->restored_at?->toIso8601String(), 'closed_at' => $a->closed_at?->toIso8601String(), 'actual_recovery_time_minutes' => $a->actual_recovery_time_minutes, 'actual_recovery_point_minutes' => $a->actual_recovery_point_minutes, 'outcome' => $a->outcome?->value, 'service_snapshot' => $a->service_snapshot, 'plan_snapshot' => $a->plan_snapshot];
    }

    private function assertCanManage(User $actor): void
    {
        Enterprise::assertEnabled('resilience');
        abort_unless($actor->isSuperAdmin() || $actor->can('Manage Resilience'), 403, 'You cannot manage operational resilience.');
    }
}
