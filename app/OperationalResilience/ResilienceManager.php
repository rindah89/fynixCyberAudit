<?php

namespace App\OperationalResilience;

use App\Enums\RecoveryExerciseOutcome;
use App\Models\BusinessImpactAnalysis;
use App\Models\BusinessService;
use App\Models\RecoveryExercise;
use App\Models\RecoveryPlan;
use App\Models\User;
use App\Support\Enterprise;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResilienceManager
{
    public function createImpactAnalysis(BusinessService $service, User $actor, array $data): BusinessImpactAnalysis
    {
        $this->assertCanManage($actor);
        $approve = (bool) ($data['approve'] ?? false);
        unset($data['approve']);

        return DB::transaction(function () use ($service, $actor, $data, $approve) {
            $locked = BusinessService::query()->lockForUpdate()->findOrFail($service->id);
            $version = ((int) $locked->impactAnalyses()->max('version')) + 1;

            return $locked->impactAnalyses()->create($data + [
                'version' => $version, 'analyst_id' => $actor->id,
                'approved_by' => $approve ? $actor->id : null, 'approved_at' => $approve ? now() : null,
            ]);
        });
    }

    public function createRecoveryPlan(BusinessService $service, User $actor, array $data): RecoveryPlan
    {
        $this->assertCanManage($actor);
        $approve = (bool) ($data['approve'] ?? false);
        unset($data['approve']);

        return DB::transaction(function () use ($service, $actor, $data, $approve) {
            $locked = BusinessService::query()->lockForUpdate()->findOrFail($service->id);
            $version = ((int) $locked->recoveryPlans()->max('version')) + 1;

            return $locked->recoveryPlans()->create($data + [
                'version' => $version, 'status' => $approve ? 'approved' : 'draft',
                'approved_by' => $approve ? $actor->id : null, 'approved_at' => $approve ? now() : null,
            ]);
        });
    }

    public function completeExercise(RecoveryExercise $exercise, User $actor, array $data): RecoveryExercise
    {
        $this->assertCanManage($actor);

        return DB::transaction(function () use ($exercise, $actor, $data) {
            $locked = RecoveryExercise::query()->lockForUpdate()->findOrFail($exercise->id);
            if ($locked->completed_at) {
                throw ValidationException::withMessages(['recovery_exercise_id' => 'Completed exercises cannot be completed again.']);
            }
            $plan = RecoveryPlan::query()->with('businessService')->lockForUpdate()->findOrFail($locked->recovery_plan_id);
            $bia = $plan->businessService->latestApprovedImpactAnalysis()->lockForUpdate()->first();
            if (! $bia) {
                throw ValidationException::withMessages(['recovery_plan_id' => 'An approved impact analysis is required before exercise completion.']);
            }

            $rtoMet = $data['actual_recovery_time_minutes'] <= $bia->recovery_time_objective_minutes;
            $rpoMet = $data['actual_recovery_point_minutes'] <= $bia->recovery_point_objective_minutes;
            $outcome = $rtoMet && $rpoMet ? RecoveryExerciseOutcome::Passed
                : ($rtoMet || $rpoMet ? RecoveryExerciseOutcome::Partial : RecoveryExerciseOutcome::Failed);
            $locked->update($data + [
                'completed_by' => $actor->id,
                'completed_at' => now(),
                'rto_objective_minutes' => $bia->recovery_time_objective_minutes,
                'rpo_objective_minutes' => $bia->recovery_point_objective_minutes,
                'outcome' => $outcome,
            ]);

            if ($outcome !== RecoveryExerciseOutcome::Passed) {
                $locked->issue()->create([
                    'business_service_id' => $plan->business_service_id,
                    'owner_id' => $plan->owner_id,
                    'title' => 'Recovery exercise missed resilience objectives',
                    'description' => sprintf(
                        'Exercise achieved RTO %d/%d minutes and RPO %d/%d minutes.',
                        $data['actual_recovery_time_minutes'], $bia->recovery_time_objective_minutes,
                        $data['actual_recovery_point_minutes'], $bia->recovery_point_objective_minutes,
                    ),
                    'severity' => $outcome === RecoveryExerciseOutcome::Failed ? 'critical' : 'high',
                    'status' => 'open',
                ]);
            }

            return $locked->fresh(['issue']);
        });
    }

    private function assertCanManage(User $actor): void
    {
        Enterprise::assertEnabled('resilience');
        if (! $actor->isSuperAdmin() && ! $actor->can('Manage Resilience')) {
            abort(403, 'You cannot manage operational resilience.');
        }
    }
}
