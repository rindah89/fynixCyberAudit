<?php

namespace App\Models;

use App\Enums\AuditManagementPosition;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditManagementResponse extends Model
{
    use HasFactory;

    protected $fillable = ['audit_finding_id', 'version', 'position', 'response', 'action_plan', 'target_date', 'finding_snapshot', 'responded_by', 'responded_at', 'fingerprint'];

    protected $casts = ['position' => AuditManagementPosition::class, 'target_date' => 'date', 'finding_snapshot' => 'array', 'responded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit management responses are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit management response history cannot be deleted.'));
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(AuditFinding::class, 'audit_finding_id');
    }

    public function respondent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by')->withTrashed();
    }
}
