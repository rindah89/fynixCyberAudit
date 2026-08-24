<?php

namespace App\ThirdPartyRisk;

use App\Enums\RiskIndicatorDirection;
use App\Enums\RiskIndicatorStatus;
use App\Enums\ThirdPartyContractDecision;
use App\Enums\ThirdPartyEngagementStatus;
use App\Enums\ThirdPartyMonitoringCategory;
use App\Enums\ThirdPartyRiskDecisionType;
use App\Models\ThirdPartyContractRiskReview;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementMonitoringIndicator;
use App\Models\ThirdPartyEngagementMonitoringObservation;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRiskAssessment;
use App\Models\VendorRiskDecision;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ThirdPartyEngagementMonitoringManager
{
    public function define(User $actor, ThirdPartyEngagement $engagement, array $data): ThirdPartyEngagementMonitoringIndicator
    {
        $this->assertCanManage($actor);

        return DB::transaction(function () use ($actor, $engagement, $data): ThirdPartyEngagementMonitoringIndicator {
            [$locked, $review] = $this->lockCurrentContext($engagement);
            $this->assertCanManage($actor);
            $data = Validator::make($data, self::definitionRules())->validate();
            $this->assertDecimal('warning_threshold', $data['warning_threshold']);
            $this->assertDecimal('critical_threshold', $data['critical_threshold']);
            $data['warning_threshold'] = $this->canonicalDecimal($data['warning_threshold']);
            $data['critical_threshold'] = $this->canonicalDecimal($data['critical_threshold']);
            $direction = RiskIndicatorDirection::from($data['direction']);
            $comparison = $this->compare($data['critical_threshold'], $data['warning_threshold']);
            if (($direction === RiskIndicatorDirection::HigherIsWorse && $comparison <= 0) || ($direction === RiskIndicatorDirection::LowerIsWorse && $comparison >= 0)) {
                throw ValidationException::withMessages(['critical_threshold' => 'The critical threshold must be beyond the warning threshold in the adverse direction.']);
            }
            if ($locked->monitoringIndicators()->count() >= 100) {
                throw ValidationException::withMessages(['engagement' => 'An engagement is limited to 100 retained monitoring definition versions.']);
            }
            $owner = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($data['owner_id']);
            $code = strtoupper($data['code']);
            $version = ((int) $locked->monitoringIndicators()->where('code', $code)->max('version')) + 1;
            $at = now()->startOfSecond();
            $payload = ['code' => $code, 'name' => $data['name'], 'description' => $data['description'] ?? null, 'category' => $data['category'], 'unit' => $data['unit'],
                'direction' => $data['direction'], 'warning_threshold' => $data['warning_threshold'], 'critical_threshold' => $data['critical_threshold'],
                'frequency_days' => $data['frequency_days'], 'owner_id' => $owner->id, 'measurement_method' => $data['measurement_method'],
                'third_party_engagement_id' => $locked->id, 'version' => $version, 'engagement_snapshot' => $this->engagementSnapshot($locked),
                'contract_review_snapshot' => $review->toArray(), 'risk_approval_snapshot' => $locked->approval_snapshot, 'defined_by' => $actor->id, 'defined_at' => $at->toIso8601String()];

            return $locked->monitoringIndicators()->create($payload + ['fingerprint' => $this->fingerprint($payload)])
                ->load(['owner:id,name,email', 'definer:id,name']);
        }, 3);
    }

    public function observe(User $actor, ThirdPartyEngagementMonitoringIndicator $indicator, array $data): ThirdPartyEngagementMonitoringObservation
    {
        return DB::transaction(function () use ($actor, $indicator, $data): ThirdPartyEngagementMonitoringObservation {
            $engagementId = ThirdPartyEngagementMonitoringIndicator::query()->whereKey($indicator->id)->value('third_party_engagement_id');
            [$engagement, $review] = $this->lockCurrentContext(ThirdPartyEngagement::query()->findOrFail($engagementId));
            $locked = ThirdPartyEngagementMonitoringIndicator::query()->where('third_party_engagement_id', $engagement->id)->lockForUpdate()->findOrFail($indicator->id);
            $this->assertCanObserve($actor, $engagement, $locked);
            $data = Validator::make($data, self::observationRules())->validate();
            $this->assertDecimal('observed_value', $data['observed_value']);
            $data['observed_value'] = $this->canonicalDecimal($data['observed_value']);
            if ($locked->id !== $engagement->monitoringIndicators()->where('code', $locked->code)->latest('version')->value('id') || data_get($locked->contract_review_snapshot, 'id') !== $review->id) {
                throw ValidationException::withMessages(['indicator' => 'A current monitoring definition bound to the accepted contract review is required.']);
            }
            if ($locked->observations()->count() >= 1000) {
                throw ValidationException::withMessages(['indicator' => 'An indicator is limited to 1,000 retained observations.']);
            }
            $observedAt = Carbon::parse($data['observed_at'])->startOfSecond();
            if ($observedAt->isFuture()) {
                throw ValidationException::withMessages(['observed_at' => 'Observation time cannot be in the future.']);
            }
            $latestObservation = ThirdPartyEngagementMonitoringObservation::query()->where('third_party_engagement_monitoring_indicator_id', $locked->id)->latest('version')->first();
            if ($observedAt->lt($locked->defined_at) || ($latestObservation && $observedAt->lt($latestObservation->observed_at))) {
                throw ValidationException::withMessages(['observed_at' => 'Observation time must follow the definition and prior retained observation.']);
            }
            $status = $this->status($locked, $data['observed_value']);
            $version = ((int) $locked->observations()->max('version')) + 1;
            $at = now()->startOfSecond();
            $payload = ['third_party_engagement_monitoring_indicator_id' => $locked->id, 'version' => $version, 'observed_value' => $data['observed_value'],
                'status' => $status->value, 'reason' => "Observed {$data['observed_value']} {$locked->unit}; derived {$status->value} against {$locked->direction->value} warning {$locked->warning_threshold} and critical {$locked->critical_threshold}.",
                'notes' => $data['notes'] ?? null, 'source_reference' => $data['source_reference'] ?? null,
                'indicator_snapshot' => Arr::only($locked->toArray(), ['id', 'third_party_engagement_id', 'version', 'code', 'name', 'description', 'category', 'unit', 'direction', 'warning_threshold', 'critical_threshold', 'frequency_days', 'owner_id', 'measurement_method', 'fingerprint']),
                'engagement_snapshot' => $this->engagementSnapshot($engagement), 'contract_review_snapshot' => $review->toArray(), 'risk_approval_snapshot' => $engagement->approval_snapshot,
                'observed_by' => $actor->id, 'observed_at' => $observedAt->toIso8601String(), 'recorded_at' => $at->toIso8601String()];

            return $locked->observations()->create($payload + ['fingerprint' => $this->fingerprint($payload)])->load('observer:id,name');
        }, 3);
    }

    public static function definitionRules(): array
    {
        return ['code' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'], 'name' => 'required|string|max:255', 'description' => 'nullable|string|max:30000',
            'category' => ['required', Rule::enum(ThirdPartyMonitoringCategory::class)], 'unit' => 'required|string|max:50',
            'direction' => ['required', Rule::enum(RiskIndicatorDirection::class)], 'warning_threshold' => 'required|string', 'critical_threshold' => 'required|string',
            'frequency_days' => 'required|integer|min:1|max:366', 'owner_id' => 'required|integer|exists:users,id', 'measurement_method' => 'required|string|max:30000',
            'version' => 'prohibited', 'engagement_snapshot' => 'prohibited', 'contract_review_snapshot' => 'prohibited', 'risk_approval_snapshot' => 'prohibited', 'defined_by' => 'prohibited', 'defined_at' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    public static function observationRules(): array
    {
        return ['observed_value' => 'required|string', 'observed_at' => 'required|date', 'notes' => 'nullable|string|max:30000', 'source_reference' => 'nullable|string|max:255',
            'version' => 'prohibited', 'status' => 'prohibited', 'reason' => 'prohibited', 'indicator_snapshot' => 'prohibited', 'engagement_snapshot' => 'prohibited', 'contract_review_snapshot' => 'prohibited', 'risk_approval_snapshot' => 'prohibited', 'observed_by' => 'prohibited', 'recorded_at' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    private function lockCurrentContext(ThirdPartyEngagement $engagement): array
    {
        $vendorId = ThirdPartyEngagement::query()->whereKey($engagement->id)->value('vendor_id');
        $vendor = Vendor::withTrashed()->lockForUpdate()->findOrFail($vendorId);
        $locked = ThirdPartyEngagement::query()->where('vendor_id', $vendorId)->lockForUpdate()->findOrFail($engagement->id);
        if ($locked->status !== ThirdPartyEngagementStatus::Active || $locked->term_end_at->copy()->endOfDay()->isPast()) {
            throw ValidationException::withMessages(['engagement' => 'Monitoring requires a current active governed engagement.']);
        }
        $review = ThirdPartyContractRiskReview::query()->where('third_party_engagement_id', $locked->id)->lockForUpdate()->latest('version')->first();
        $assessment = VendorRiskAssessment::query()->where('vendor_id', $vendor->id)->lockForUpdate()->latest('version')->first();
        $decision = VendorRiskDecision::query()->where('vendor_id', $vendor->id)->lockForUpdate()->latest('decided_at')->first();
        $risks = $vendor->risks()->orderBy('risks.id')->lockForUpdate()->get();
        $vendor->setRelation('risks', $risks);
        $currentGovernance = $assessment ? $vendor->thirdPartyRiskSnapshot($assessment) : null;
        if (! $review || ! in_array($review->decision, [ThirdPartyContractDecision::Approved, ThirdPartyContractDecision::ConditionallyApproved], true)
            || $review->expires_at->toDateString() < $locked->term_end_at->toDateString()
            || ! $assessment || ! $decision || data_get($review->risk_approval_snapshot, 'assessment.id') !== $assessment->id
            || ! in_array($decision->decision, [ThirdPartyRiskDecisionType::Approved, ThirdPartyRiskDecisionType::ConditionallyApproved], true)
            || $decision->expires_at?->copy()->endOfDay()->isPast()
            || data_get($review->risk_approval_snapshot, 'decision.id') !== $decision->id
            || data_get($review->risk_approval_snapshot, 'governance.fingerprint') !== data_get($currentGovernance, 'fingerprint')) {
            throw ValidationException::withMessages(['contract_review' => 'Monitoring requires a current accepted contract-risk review covering the engagement term.']);
        }

        return [$locked, $review];
    }

    private function engagementSnapshot(ThirdPartyEngagement $engagement): array
    {
        $engagement->load(['businessOwner:id,name,email', 'proposer:id,name,email', 'approver:id,name,email']);

        return Arr::only($engagement->toArray(), ['id', 'vendor_id', 'code', 'name', 'service_description', 'business_owner_id', 'criticality', 'data_access', 'status', 'term_start_at', 'term_end_at', 'next_review_at', 'approved_by', 'approved_at', 'activated_at', 'vendor_snapshot', 'approval_snapshot', 'governed_at', 'business_owner', 'proposer', 'approver']);
    }

    private function assertCanManage(User $actor): void
    {
        abort_unless($actor->isSuperAdmin() || $actor->can('Manage Third Party Risk'), 403);
    }

    private function assertCanObserve(User $actor, ThirdPartyEngagement $engagement, ThirdPartyEngagementMonitoringIndicator $indicator): void
    {
        abort_unless($actor->isSuperAdmin() || $actor->can('Manage Third Party Risk') || $actor->id === $indicator->owner_id || $actor->id === $engagement->business_owner_id, 403);
    }

    private function assertDecimal(string $field, mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/^-?\d{1,15}(?:\.\d{1,6})?$/', $value)) {
            throw ValidationException::withMessages([$field => 'Numeric values support up to 15 integer digits and 6 decimal places.']);
        }
    }

    private function canonicalDecimal(string $value): string
    {
        $negative = str_starts_with($value, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = str_pad($fraction, 6, '0');

        return ($negative && ($integer !== '0' || trim($fraction, '0') !== '') ? '-' : '').$integer.'.'.$fraction;
    }

    private function status(ThirdPartyEngagementMonitoringIndicator $indicator, string $value): RiskIndicatorStatus
    {
        $critical = $this->compare($value, $indicator->critical_threshold);
        $warning = $this->compare($value, $indicator->warning_threshold);

        return $indicator->direction === RiskIndicatorDirection::HigherIsWorse ? ($critical >= 0 ? RiskIndicatorStatus::Critical : ($warning >= 0 ? RiskIndicatorStatus::Warning : RiskIndicatorStatus::Normal)) : ($critical <= 0 ? RiskIndicatorStatus::Critical : ($warning <= 0 ? RiskIndicatorStatus::Warning : RiskIndicatorStatus::Normal));
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function compare(string $left, string $right): int
    {
        $normalize = static function (string $value): array {
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
        } $result = strlen($li) <=> strlen($ri);
        if ($result === 0) {
            $result = $li <=> $ri;
        } if ($result === 0) {
            $length = max(strlen($lf), strlen($rf));
            $result = str_pad($lf, $length, '0') <=> str_pad($rf, $length, '0');
        }

        return $ln ? -$result : $result;
    }
}
