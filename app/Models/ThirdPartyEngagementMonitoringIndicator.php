<?php

namespace App\Models;

use App\Enums\RiskIndicatorDirection;
use App\Enums\RiskIndicatorStatus;
use App\Enums\ThirdPartyMonitoringCategory;
use App\Enums\ThirdPartyMonitoringState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ThirdPartyEngagementMonitoringIndicator extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_engagement_id', 'version', 'code', 'name', 'description', 'category', 'unit', 'direction', 'warning_threshold', 'critical_threshold', 'frequency_days', 'owner_id', 'measurement_method', 'engagement_snapshot', 'contract_review_snapshot', 'risk_approval_snapshot', 'defined_by', 'defined_at', 'fingerprint'];

    protected $casts = ['category' => ThirdPartyMonitoringCategory::class, 'direction' => RiskIndicatorDirection::class, 'warning_threshold' => 'decimal:6', 'critical_threshold' => 'decimal:6', 'engagement_snapshot' => 'array', 'contract_review_snapshot' => 'array', 'risk_approval_snapshot' => 'array', 'defined_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Third-party engagement monitoring definitions are append-only.'));
        static::deleting(fn () => throw new LogicException('Third-party engagement monitoring definitions are append-only.'));
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagement::class, 'third_party_engagement_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function definer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'defined_by')->withTrashed();
    }

    public function observations(): HasMany
    {
        return $this->hasMany(ThirdPartyEngagementMonitoringObservation::class, 'third_party_engagement_monitoring_indicator_id')->orderBy('version');
    }

    public function latestObservation(): HasOne
    {
        return $this->hasOne(ThirdPartyEngagementMonitoringObservation::class, 'third_party_engagement_monitoring_indicator_id')->latestOfMany('version');
    }

    public function latestObservations(): HasMany
    {
        return $this->hasMany(ThirdPartyEngagementMonitoringObservation::class, 'third_party_engagement_monitoring_indicator_id')->latest('version')->limit(10);
    }

    public function getMonitoringStatusAttribute(): ThirdPartyMonitoringState
    {
        $loadedLatest = $this->relationLoaded('latestObservation') ? $this->getRelation('latestObservation') : null;
        $latest = $loadedLatest instanceof ThirdPartyEngagementMonitoringObservation
            ? $loadedLatest
            : ThirdPartyEngagementMonitoringObservation::query()->where('third_party_engagement_monitoring_indicator_id', $this->id)->latest('version')->first();
        if (! $latest) {
            return $this->defined_at?->copy()->addDays($this->frequency_days)->isPast() ? ThirdPartyMonitoringState::ObservationOverdue : ThirdPartyMonitoringState::AwaitingObservation;
        }
        if ($latest->status === RiskIndicatorStatus::Critical) {
            return ThirdPartyMonitoringState::ActionRequired;
        }

        if ($latest->observed_at->copy()->addDays($this->frequency_days)->isPast()) {
            return ThirdPartyMonitoringState::ObservationOverdue;
        }

        return $latest->status === RiskIndicatorStatus::Warning ? ThirdPartyMonitoringState::Warning : ThirdPartyMonitoringState::Normal;
    }
}
