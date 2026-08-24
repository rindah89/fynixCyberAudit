<?php

namespace App\Services;

use App\Enums\RiskDomain;
use App\Enums\RiskIndicatorDirection;
use App\Enums\RiskIndicatorStatus;
use App\Models\BusinessService;
use App\Models\Risk;
use App\Models\RiskGovernanceProfile;
use App\Models\RiskIndicator;
use App\Models\RiskIndicatorObservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RiskIndicatorManager
{
    /** @param array<string, mixed> $data */
    public function define(Risk $risk, User $actor, array $data): RiskIndicator
    {
        return DB::transaction(function () use ($risk, $actor, $data): RiskIndicator {
            $lockedRisk = Risk::query()->lockForUpdate()->findOrFail($risk->id);
            $profile = RiskGovernanceProfile::query()->where('risk_id', $risk->id)->lockForUpdate()->first();
            $service = $profile?->business_service_id ? BusinessService::query()->lockForUpdate()->find($profile->business_service_id) : null;
            if (! $actor->can('Manage Risk Portfolio')) {
                abort(403, 'You cannot define operational risk indicators.');
            }
            $this->assertOperationalContext($lockedRisk, $profile, $service);
            $this->assertDecimal('warning_threshold', $data['warning_threshold']);
            $this->assertDecimal('critical_threshold', $data['critical_threshold']);
            $this->assertThresholdOrder(RiskIndicatorDirection::from($data['direction']), $data['warning_threshold'], $data['critical_threshold']);

            return $lockedRisk->riskIndicators()->create($data)->load('owner:id,name');
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function observe(RiskIndicator $indicator, User $actor, array $data): RiskIndicatorObservation
    {
        return DB::transaction(function () use ($indicator, $actor, $data): RiskIndicatorObservation {
            $locked = RiskIndicator::query()->lockForUpdate()->findOrFail($indicator->id);
            $risk = Risk::query()->lockForUpdate()->findOrFail($locked->risk_id);
            $profile = RiskGovernanceProfile::query()->where('risk_id', $risk->id)->lockForUpdate()->first();
            $service = $profile?->business_service_id ? BusinessService::query()->lockForUpdate()->find($profile->business_service_id) : null;
            if (! $actor->can('Manage Risk Portfolio') && $locked->owner_id !== $actor->id && $profile?->owner_id !== $actor->id) {
                abort(403, 'You cannot record an observation for this risk indicator.');
            }
            $this->assertOperationalContext($risk, $profile, $service);
            if (! $locked->is_active) {
                throw ValidationException::withMessages(['risk_indicator_id' => 'Inactive risk indicators cannot receive observations.']);
            }

            $observedAt = isset($data['observed_at']) ? Carbon::parse($data['observed_at']) : now();
            if ($observedAt->isFuture()) {
                throw ValidationException::withMessages(['observed_at' => 'Observation time cannot be in the future.']);
            }
            $this->assertDecimal('observed_value', $data['observed_value']);
            $status = $this->status($locked, $data['observed_value']);
            $observation = $locked->observations()->create([
                'observed_by' => $actor->id,
                'observed_value' => $data['observed_value'],
                'unit_snapshot' => $locked->unit,
                'direction_snapshot' => $locked->direction->value,
                'warning_threshold_snapshot' => $locked->warning_threshold,
                'critical_threshold_snapshot' => $locked->critical_threshold,
                'status' => $status,
                'reason' => $this->reason($locked, $data['observed_value'], $status),
                'notes' => $data['notes'] ?? null,
                'source_reference' => $data['source_reference'] ?? null,
                'observed_at' => $observedAt,
            ]);
            if (! $locked->last_observed_at || $observedAt->greaterThanOrEqualTo($locked->last_observed_at)) {
                $locked->update([
                    'last_observed_at' => $observedAt,
                    'last_status' => $status->value,
                    'next_due_at' => $locked->frequency->nextDueAt($observedAt),
                ]);
            }

            return $observation->load('observer:id,name');
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(RiskIndicator $indicator, User $actor, array $data): RiskIndicator
    {
        return DB::transaction(function () use ($indicator, $actor, $data): RiskIndicator {
            $locked = RiskIndicator::query()->lockForUpdate()->findOrFail($indicator->id);
            $risk = Risk::query()->lockForUpdate()->findOrFail($locked->risk_id);
            $profile = RiskGovernanceProfile::query()->where('risk_id', $risk->id)->lockForUpdate()->first();
            $service = $profile?->business_service_id ? BusinessService::query()->lockForUpdate()->find($profile->business_service_id) : null;
            if (! $actor->can('Manage Risk Portfolio')) {
                abort(403, 'You cannot update operational risk indicators.');
            }
            $this->assertOperationalContext($risk, $profile, $service);
            $this->assertDecimal('warning_threshold', $data['warning_threshold']);
            $this->assertDecimal('critical_threshold', $data['critical_threshold']);
            $this->assertThresholdOrder(RiskIndicatorDirection::from($data['direction']), $data['warning_threshold'], $data['critical_threshold']);
            $locked->update($data);

            return $locked->refresh()->load('owner:id,name');
        }, 3);
    }

    private function assertOperationalContext(Risk $risk, ?RiskGovernanceProfile $profile, ?BusinessService $service): void
    {
        if ($risk->domain !== RiskDomain::Operational || ! $risk->is_active || ! $profile?->business_service_id || ! $service || $service->status !== 'active') {
            throw ValidationException::withMessages(['risk_id' => 'Indicators require an active governed operational risk with an active business service.']);
        }
    }

    private function assertThresholdOrder(RiskIndicatorDirection $direction, string $warning, string $critical): void
    {
        $comparison = $this->compare($critical, $warning);
        $valid = $direction === RiskIndicatorDirection::HigherIsWorse ? $comparison > 0 : $comparison < 0;
        if (! $valid) {
            throw ValidationException::withMessages(['critical_threshold' => 'The critical threshold must be beyond the warning threshold in the selected adverse direction.']);
        }
    }

    private function assertDecimal(string $field, mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/^-?\d{1,15}(?:\.\d{1,6})?$/', $value)) {
            throw ValidationException::withMessages([$field => 'Numeric values support up to 15 integer digits and 6 decimal places.']);
        }
    }

    private function status(RiskIndicator $indicator, string $value): RiskIndicatorStatus
    {
        $critical = $this->compare($value, $indicator->critical_threshold);
        $warning = $this->compare($value, $indicator->warning_threshold);
        if ($indicator->direction === RiskIndicatorDirection::HigherIsWorse) {
            return $critical >= 0 ? RiskIndicatorStatus::Critical : ($warning >= 0 ? RiskIndicatorStatus::Warning : RiskIndicatorStatus::Normal);
        }

        return $critical <= 0 ? RiskIndicatorStatus::Critical : ($warning <= 0 ? RiskIndicatorStatus::Warning : RiskIndicatorStatus::Normal);
    }

    private function reason(RiskIndicator $indicator, string $value, RiskIndicatorStatus $status): string
    {
        return "Observed {$value} {$indicator->unit}; {$indicator->direction->value} thresholds were warning {$indicator->warning_threshold} and critical {$indicator->critical_threshold}; derived status {$status->value}.";
    }

    private function compare(string $left, string $right): int
    {
        $normalize = function (string $value): array {
            $negative = str_starts_with($value, '-');
            [$integer, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
            $integer = ltrim($integer, '0') ?: '0';
            $fraction = rtrim($fraction, '0');
            if ($integer === '0' && $fraction === '') {
                $negative = false;
            }

            return [$negative, $integer, $fraction];
        };
        [$ln, $li, $lf] = $normalize($left);
        [$rn, $ri, $rf] = $normalize($right);
        if ($ln !== $rn) {
            return $ln ? -1 : 1;
        }
        $result = strlen($li) <=> strlen($ri);
        if ($result === 0) {
            $result = $li <=> $ri;
        }
        if ($result === 0) {
            $length = max(strlen($lf), strlen($rf));
            $result = str_pad($lf, $length, '0') <=> str_pad($rf, $length, '0');
        }

        return $ln ? -$result : $result;
    }
}
