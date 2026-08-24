<?php

namespace App\Models;

use App\Enums\AuditPlanStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class AuditPlan extends Model
{
    use HasFactory;

    protected $fillable = ['plan_year', 'name', 'objective', 'manager_id', 'status', 'created_by', 'approved_by', 'approved_at', 'approval_snapshot', 'approval_fingerprint'];

    protected $casts = ['status' => AuditPlanStatus::class, 'approved_at' => 'datetime', 'approval_snapshot' => 'array'];

    protected static function booted(): void
    {
        static::updating(function (AuditPlan $plan): void {
            if ($plan->getRawOriginal('status') === AuditPlanStatus::Approved->value) {
                throw new LogicException('Approved audit plans are immutable.');
            }
        });
        static::deleting(fn (AuditPlan $plan) => $plan->items()->exists() ? throw new LogicException('Audit plans with planning evidence cannot be deleted.') : null);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(AuditPlanItem::class);
    }
}
