<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditEngagementBaseline extends Model
{
    use HasFactory;

    protected $fillable = ['audit_id', 'audit_plan_item_id', 'objective', 'scope', 'exclusions', 'team_user_ids', 'audit_snapshot', 'plan_snapshot', 'entity_assessment_snapshot', 'launched_by', 'launched_at', 'fingerprint'];

    protected $casts = ['team_user_ids' => 'array', 'audit_snapshot' => 'array', 'plan_snapshot' => 'array', 'entity_assessment_snapshot' => 'array', 'launched_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit engagement baselines are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit engagement baselines cannot be deleted.'));
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function planItem(): BelongsTo
    {
        return $this->belongsTo(AuditPlanItem::class, 'audit_plan_item_id');
    }

    public function launcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'launched_by')->withTrashed();
    }
}
