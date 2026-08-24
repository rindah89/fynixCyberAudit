<?php

namespace App\Models;

use App\Enums\AuditProcedureOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class AuditProcedureExecution extends Model
{
    use HasFactory;

    protected $fillable = ['audit_procedure_id', 'outcome', 'result', 'exceptions', 'sample_tested', 'evidence_reference', 'evidence_manifest', 'procedure_snapshot', 'executed_by', 'executed_at', 'fingerprint'];

    protected $casts = ['outcome' => AuditProcedureOutcome::class, 'evidence_manifest' => 'array', 'procedure_snapshot' => 'array', 'executed_at' => 'datetime'];

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

    public function review(): HasOne
    {
        return $this->hasOne(AuditWorkpaperReview::class, 'audit_procedure_execution_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(AuditProcedureExecutionEvidence::class);
    }

    public function fingerprintPayload(): array
    {
        return [
            'outcome' => $this->outcome->value, 'result' => $this->result, 'exceptions' => $this->exceptions,
            'sample_tested' => $this->sample_tested, 'evidence_reference' => $this->evidence_reference,
            'evidence_manifest' => $this->evidence_manifest ?? [], 'procedure_snapshot' => $this->procedure_snapshot,
            'executed_by' => $this->executed_by, 'executed_at' => $this->executed_at->toIso8601String(),
        ];
    }
}
