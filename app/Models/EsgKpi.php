<?php

namespace App\Models;

use App\Enums\EsgKpiDirection;
use App\Enums\EsgKpiStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class EsgKpi extends Model
{
    use HasFactory;

    protected $fillable = ['esg_goal_id', 'code', 'name', 'description', 'owner_id', 'unit', 'direction', 'baseline_value', 'target_value', 'measurement_method', 'source_reference', 'frequency_days', 'next_due_at', 'last_observed_at', 'last_status', 'is_active', 'goal_snapshot', 'created_by', 'governed_at', 'fingerprint'];

    protected $casts = ['direction' => EsgKpiDirection::class, 'baseline_value' => 'decimal:6', 'target_value' => 'decimal:6', 'frequency_days' => 'integer', 'next_due_at' => 'datetime', 'last_observed_at' => 'datetime', 'last_status' => EsgKpiStatus::class, 'is_active' => 'boolean', 'goal_snapshot' => 'array', 'governed_at' => 'datetime'];

    protected $appends = ['monitoring_status'];

    protected static function booted(): void
    {
        static::updating(function (self $kpi): void {
            if (array_diff(array_keys($kpi->getDirty()), ['next_due_at', 'last_observed_at', 'last_status', 'is_active']) !== []) {
                throw new LogicException('ESG KPI definition evidence is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('ESG KPIs are retained evidence.'));
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(EsgGoal::class, 'esg_goal_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function observations(): HasMany
    {
        return $this->hasMany(EsgKpiObservation::class)->with('observer:id,name')->orderBy('version');
    }

    public function latestObservation(): HasOne
    {
        return $this->hasOne(EsgKpiObservation::class)->latestOfMany('version');
    }

    public function getMonitoringStatusAttribute(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }
        if ($this->last_status === EsgKpiStatus::TargetMet) {
            return EsgKpiStatus::TargetMet->value;
        }
        if ($this->next_due_at?->isPast()) {
            return 'overdue';
        }

        return $this->last_status?->value ?? 'awaiting_observation';
    }
}
