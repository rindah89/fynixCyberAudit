<?php

namespace App\Models;

use App\Enums\RiskIndicatorDirection;
use App\Enums\RiskIndicatorStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RiskIndicatorObservation extends Model
{
    use HasFactory;

    protected $fillable = ['risk_indicator_id', 'observed_by', 'observed_value', 'unit_snapshot', 'direction_snapshot', 'warning_threshold_snapshot', 'critical_threshold_snapshot', 'status', 'reason', 'notes', 'source_reference', 'observed_at'];

    protected $casts = [
        'observed_value' => 'decimal:6',
        'warning_threshold_snapshot' => 'decimal:6',
        'critical_threshold_snapshot' => 'decimal:6',
        'direction_snapshot' => RiskIndicatorDirection::class,
        'status' => RiskIndicatorStatus::class,
        'observed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Risk indicator observations are append-only through product interfaces.'));
        static::deleting(fn () => throw new LogicException('Risk indicator observations are retained as monitoring history.'));
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(RiskIndicator::class, 'risk_indicator_id');
    }

    public function observer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'observed_by');
    }
}
