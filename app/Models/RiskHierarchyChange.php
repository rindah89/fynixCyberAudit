<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RiskHierarchyChange extends Model
{
    protected $fillable = ['risk_id', 'previous_parent_risk_id', 'parent_risk_id', 'changed_by', 'changed_at'];

    protected $casts = ['changed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Risk hierarchy changes are immutable. Record a new change instead.'));
        static::deleting(fn () => throw new LogicException('Risk hierarchy changes are immutable.'));
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function previousParent(): BelongsTo
    {
        return $this->belongsTo(Risk::class, 'previous_parent_risk_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Risk::class, 'parent_risk_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
