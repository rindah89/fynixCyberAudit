<?php

namespace App\Models;

use App\Enums\RecoveryPlanStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class RecoveryPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_service_id', 'owner_id', 'version', 'title', 'strategy', 'activation_criteria',
        'recovery_procedure', 'communication_plan', 'status', 'approved_by', 'approved_at', 'review_due_at',
    ];

    protected $casts = ['status' => RecoveryPlanStatus::class, 'approved_at' => 'datetime', 'review_due_at' => 'date'];

    protected static function booted(): void
    {
        static::updating(function (RecoveryPlan $plan): void {
            if ($plan->getOriginal('status') === RecoveryPlanStatus::Approved->value) {
                throw new LogicException('Approved recovery plans are immutable. Create a new version instead.');
            }
        });
        static::deleting(function (RecoveryPlan $plan): void {
            if ($plan->status === RecoveryPlanStatus::Approved || $plan->exercises()->exists()) {
                throw new LogicException('Approved or exercised recovery plans are immutable.');
            }
        });
    }

    public function businessService(): BelongsTo
    {
        return $this->belongsTo(BusinessService::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(RecoveryExercise::class);
    }

    public function latestCompletedExercise(): HasOne
    {
        return $this->hasOne(RecoveryExercise::class)->whereNotNull('completed_at')->latestOfMany('completed_at');
    }
}
