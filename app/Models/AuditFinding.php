<?php

namespace App\Models;

use App\Enums\AuditFindingSeverity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class AuditFinding extends Model
{
    use HasFactory;

    protected $fillable = ['audit_id', 'audit_item_id', 'code', 'title', 'severity', 'condition', 'criteria', 'cause', 'effect', 'recommendation', 'accountable_owner_id', 'source_snapshot', 'raised_by', 'raised_at', 'fingerprint'];

    protected $casts = ['severity' => AuditFindingSeverity::class, 'source_snapshot' => 'array', 'raised_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Governed audit findings are immutable.'));
        static::deleting(fn () => throw new LogicException('Governed audit finding history cannot be deleted.'));
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function auditItem(): BelongsTo
    {
        return $this->belongsTo(AuditItem::class);
    }

    public function accountableOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accountable_owner_id')->withTrashed();
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by')->withTrashed();
    }

    public function responses(): HasMany
    {
        return $this->hasMany(AuditManagementResponse::class);
    }

    public function latestResponse(): HasOne
    {
        return $this->hasOne(AuditManagementResponse::class)->latestOfMany('version');
    }

    public function remediation(): HasOne
    {
        return $this->hasOne(AuditFindingRemediation::class);
    }
}
