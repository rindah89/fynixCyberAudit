<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class AuditableEntityAssessment extends Model
{
    use HasFactory;

    protected $fillable = ['auditable_entity_id', 'version', 'inherent_likelihood', 'inherent_impact', 'inherent_score', 'residual_likelihood', 'residual_impact', 'residual_score', 'priority_band', 'rationale', 'entity_snapshot', 'risk_snapshots', 'control_snapshots', 'governance_fingerprint', 'next_assessment_at', 'assessed_by', 'assessed_at'];

    protected $casts = ['entity_snapshot' => 'array', 'risk_snapshots' => 'array', 'control_snapshots' => 'array', 'next_assessment_at' => 'date', 'assessed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Auditable-entity assessments are immutable.'));
        static::deleting(fn () => throw new LogicException('Auditable-entity assessments are immutable.'));
    }

    public function auditableEntity(): BelongsTo
    {
        return $this->belongsTo(AuditableEntity::class)->withTrashed();
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by')->withTrashed();
    }

    public function planItems(): HasMany
    {
        return $this->hasMany(AuditPlanItem::class);
    }
}
