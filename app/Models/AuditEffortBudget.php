<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditEffortBudget extends Model
{
    use HasFactory;

    protected $fillable = ['audit_id', 'audit_procedure_id', 'user_id', 'version', 'planned_minutes', 'rationale', 'allocation_snapshot', 'set_by', 'set_at', 'fingerprint'];

    protected $casts = ['allocation_snapshot' => 'array', 'set_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit effort budget versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit effort budget history cannot be deleted.'));
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(AuditProcedure::class, 'audit_procedure_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by')->withTrashed();
    }
}
