<?php

namespace App\Models;

use App\Enums\ComplianceCaseInvestigationProcedureResult;
use Database\Factories\ComplianceCaseInvestigationProcedureExecutionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceCaseInvestigationProcedureExecution extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_case_id', 'compliance_case_investigation_plan_id', 'procedure_index', 'procedure_text',
        'result', 'summary', 'findings', 'source_reference', 'executed_by', 'executor_snapshot',
        'plan_snapshot', 'case_snapshot', 'executed_at', 'fingerprint',
    ];

    protected $casts = [
        'result' => ComplianceCaseInvestigationProcedureResult::class,
        'executor_snapshot' => 'array', 'plan_snapshot' => 'array', 'case_snapshot' => 'array', 'executed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Investigation procedure executions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Investigation procedure executions are retained.'));
    }

    protected static function newFactory(): ComplianceCaseInvestigationProcedureExecutionFactory
    {
        return ComplianceCaseInvestigationProcedureExecutionFactory::new();
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseInvestigationPlan::class, 'compliance_case_investigation_plan_id');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by')->withTrashed();
    }
}
