<?php

namespace App\Models;

use App\Enums\AuditProcedureMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class AuditProcedure extends Model
{
    use HasFactory;

    protected $fillable = ['audit_id', 'audit_item_id', 'version', 'code', 'title', 'objective', 'steps', 'method', 'population_description', 'planned_sample_size', 'assigned_to', 'due_at', 'status', 'created_by'];

    protected $casts = ['method' => AuditProcedureMethod::class, 'due_at' => 'date'];

    protected static function booted(): void
    {
        static::updating(function (AuditProcedure $procedure): void {
            if ($procedure->execution()->exists()) {
                throw new LogicException('Executed audit procedures are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Audit procedure history cannot be deleted.'));
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function auditItem(): BelongsTo
    {
        return $this->belongsTo(AuditItem::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function execution(): HasOne
    {
        return $this->hasOne(AuditProcedureExecution::class);
    }

    public function effortBudgets(): HasMany
    {
        return $this->hasMany(AuditEffortBudget::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(AuditTimeEntry::class);
    }
}
