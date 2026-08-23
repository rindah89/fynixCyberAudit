<?php

namespace App\Models;

use App\Enums\RecoveryExerciseOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class RecoveryExercise extends Model
{
    use HasFactory;

    protected $fillable = [
        'recovery_plan_id', 'facilitator_id', 'completed_by', 'incident_id', 'scenario', 'scheduled_at', 'completed_at',
        'actual_recovery_time_minutes', 'actual_recovery_point_minutes', 'rto_objective_minutes',
        'rpo_objective_minutes', 'outcome', 'observations', 'evidence_reference',
    ];

    protected $casts = ['scheduled_at' => 'datetime', 'completed_at' => 'datetime', 'outcome' => RecoveryExerciseOutcome::class];

    protected static function booted(): void
    {
        static::updating(function (RecoveryExercise $exercise): void {
            if ($exercise->getOriginal('completed_at')) {
                throw new LogicException('Completed recovery exercises are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Recovery exercises are immutable.'));
    }

    public function recoveryPlan(): BelongsTo
    {
        return $this->belongsTo(RecoveryPlan::class);
    }

    public function facilitator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'facilitator_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function issue(): HasOne
    {
        return $this->hasOne(ResilienceIssue::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(RecoveryExerciseEvidence::class);
    }
}
