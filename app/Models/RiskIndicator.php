<?php

namespace App\Models;

use App\Enums\RiskIndicatorDirection;
use App\Enums\RiskIndicatorFrequency;
use App\Enums\RiskIndicatorStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class RiskIndicator extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['risk_id', 'owner_id', 'code', 'name', 'description', 'unit', 'direction', 'warning_threshold', 'critical_threshold', 'frequency', 'next_due_at', 'last_observed_at', 'last_status', 'is_active'];

    protected $casts = [
        'direction' => RiskIndicatorDirection::class,
        'frequency' => RiskIndicatorFrequency::class,
        'warning_threshold' => 'decimal:6',
        'critical_threshold' => 'decimal:6',
        'next_due_at' => 'datetime',
        'last_observed_at' => 'datetime',
        'last_status' => RiskIndicatorStatus::class,
        'is_active' => 'boolean',
    ];

    protected $appends = ['monitoring_status', 'schedule_status'];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(RiskIndicatorObservation::class);
    }

    public function latestObservation(): HasOne
    {
        return $this->hasOne(RiskIndicatorObservation::class)->latestOfMany('observed_at');
    }

    public function getMonitoringStatusAttribute(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }
        if ($this->last_status === RiskIndicatorStatus::Critical) {
            return 'critical';
        }
        if ($this->last_status === RiskIndicatorStatus::Warning) {
            return 'warning';
        }
        if ($this->next_due_at?->isPast()) {
            return 'overdue';
        }

        return $this->last_status?->value ?? 'awaiting_observation';
    }

    public function getScheduleStatusAttribute(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        return $this->next_due_at?->isPast() ? 'overdue' : 'scheduled';
    }
}
