<?php

namespace App\Models;

use App\Enums\TechnologyExposureState;
use App\Enums\TechnologyExposureType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TechnologyExposureAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'risk_id', 'version', 'asset_id_snapshot', 'assessed_by', 'exposure_type', 'title', 'threat_scenario',
        'vulnerability_reference', 'vulnerability_description', 'source_reference', 'inherent_likelihood',
        'inherent_impact', 'inherent_score', 'residual_likelihood', 'residual_impact', 'residual_score',
        'appetite_threshold_snapshot', 'state', 'recommended_response', 'review_due_at', 'asset_snapshot',
        'governance_snapshot', 'governance_fingerprint', 'assessed_at',
    ];

    protected $casts = [
        'exposure_type' => TechnologyExposureType::class,
        'state' => TechnologyExposureState::class,
        'asset_snapshot' => 'array',
        'governance_snapshot' => 'array',
        'review_due_at' => 'date',
        'assessed_at' => 'datetime',
    ];

    protected $appends = ['schedule_status'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Technology exposure assessments are append-only through product interfaces.'));
        static::deleting(fn () => throw new LogicException('Technology exposure assessments are retained as governance history.'));
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id_snapshot');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function getScheduleStatusAttribute(): string
    {
        return $this->review_due_at->copy()->endOfDay()->isPast() ? 'review_overdue' : 'scheduled';
    }

    public function getAssetSnapshotJsonAttribute(): string
    {
        return json_encode($this->asset_snapshot, JSON_THROW_ON_ERROR);
    }

    public function getGovernanceSnapshotJsonAttribute(): string
    {
        return json_encode($this->governance_snapshot, JSON_THROW_ON_ERROR);
    }
}
