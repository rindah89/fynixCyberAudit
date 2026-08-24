<?php

namespace App\Models;

use App\Enums\AuditTimeEntryType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class AuditTimeEntry extends Model
{
    use HasFactory;

    protected $fillable = ['audit_id', 'audit_procedure_id', 'user_id', 'entry_type', 'reverses_time_entry_id', 'work_date', 'minutes', 'activity', 'notes', 'source_reference', 'budget_snapshot', 'procedure_snapshot', 'entered_by', 'entered_at', 'fingerprint'];

    protected $casts = ['entry_type' => AuditTimeEntryType::class, 'work_date' => 'date', 'budget_snapshot' => 'array', 'procedure_snapshot' => 'array', 'entered_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit time entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit time-entry history cannot be deleted.'));
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

    public function entrant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by')->withTrashed();
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_time_entry_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_time_entry_id');
    }
}
