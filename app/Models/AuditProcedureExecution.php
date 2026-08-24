<?php

namespace App\Models;

use App\Enums\AuditProcedureOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditProcedureExecution extends Model
{
    use HasFactory;

    protected $fillable = ['audit_procedure_id', 'outcome', 'result', 'exceptions', 'sample_tested', 'evidence_reference', 'procedure_snapshot', 'executed_by', 'executed_at', 'fingerprint'];

    protected $casts = ['outcome' => AuditProcedureOutcome::class, 'procedure_snapshot' => 'array', 'executed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit procedure executions are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit procedure execution history cannot be deleted.'));
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(AuditProcedure::class, 'audit_procedure_id');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by')->withTrashed();
    }
}
