<?php

namespace App\Models;

use App\Enums\RiskIndicatorStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyEngagementMonitoringObservation extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_engagement_monitoring_indicator_id', 'version', 'observed_value', 'status', 'reason', 'notes', 'source_reference', 'indicator_snapshot', 'engagement_snapshot', 'contract_review_snapshot', 'risk_approval_snapshot', 'observed_by', 'observed_at', 'recorded_at', 'fingerprint'];

    protected $casts = ['observed_value' => 'decimal:6', 'status' => RiskIndicatorStatus::class, 'indicator_snapshot' => 'array', 'engagement_snapshot' => 'array', 'contract_review_snapshot' => 'array', 'risk_approval_snapshot' => 'array', 'observed_at' => 'datetime', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Third-party engagement monitoring observations are append-only.'));
        static::deleting(fn () => throw new LogicException('Third-party engagement monitoring observations are append-only.'));
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagementMonitoringIndicator::class, 'third_party_engagement_monitoring_indicator_id');
    }

    public function observer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'observed_by')->withTrashed();
    }
}
