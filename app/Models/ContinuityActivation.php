<?php

namespace App\Models;

use App\Enums\ContinuityActivationStatus;
use App\Enums\ContinuityRecoveryOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ContinuityActivation extends Model
{
    use HasFactory;

    protected $fillable = ['recovery_plan_id', 'business_service_id', 'incident_id', 'activated_by', 'status', 'disruption_summary', 'business_impact', 'started_at', 'restored_at', 'closed_at', 'actual_recovery_time_minutes', 'actual_recovery_point_minutes', 'outcome', 'service_snapshot', 'plan_snapshot'];

    protected $casts = ['status' => ContinuityActivationStatus::class, 'outcome' => ContinuityRecoveryOutcome::class, 'started_at' => 'datetime', 'restored_at' => 'datetime', 'closed_at' => 'datetime', 'service_snapshot' => 'array', 'plan_snapshot' => 'array'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Continuity activations are retained governance history.'));
    }

    public function recoveryPlan(): BelongsTo
    {
        return $this->belongsTo(RecoveryPlan::class);
    }

    public function businessService(): BelongsTo
    {
        return $this->belongsTo(BusinessService::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(ContinuityActivationEvent::class)->orderBy('version');
    }
}
