<?php

namespace App\Models;

use App\Enums\AuditPlanItemStatus;
use App\Enums\AuditPlanStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class AuditPlanItem extends Model
{
    use HasFactory;

    protected $fillable = ['audit_plan_id', 'auditable_entity_id', 'auditable_entity_assessment_id', 'audit_id', 'status', 'planned_start_at', 'planned_end_at', 'rationale', 'entity_assessment_snapshot', 'priority_rank', 'created_by'];

    protected $casts = ['status' => AuditPlanItemStatus::class, 'planned_start_at' => 'date', 'planned_end_at' => 'date', 'entity_assessment_snapshot' => 'array'];

    protected static function booted(): void
    {
        static::updating(function (AuditPlanItem $item): void {
            if ($item->plan()->first()?->status === AuditPlanStatus::Approved) {
                throw new LogicException('Approved audit plan items are immutable. Create a new plan instead.');
            }
        });
        static::deleting(function (AuditPlanItem $item): void {
            if ($item->plan()->first()?->status === AuditPlanStatus::Approved) {
                throw new LogicException('Approved audit plan items are immutable.');
            }
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AuditPlan::class, 'audit_plan_id');
    }

    public function auditableEntity(): BelongsTo
    {
        return $this->belongsTo(AuditableEntity::class)->withTrashed();
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(AuditableEntityAssessment::class, 'auditable_entity_assessment_id');
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function engagementBaseline(): HasOne
    {
        return $this->hasOne(AuditEngagementBaseline::class);
    }
}
