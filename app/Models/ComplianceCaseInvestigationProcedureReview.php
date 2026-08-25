<?php

namespace App\Models;

use App\Enums\ComplianceCaseInvestigationProcedureReviewDecision;
use Database\Factories\ComplianceCaseInvestigationProcedureReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceCaseInvestigationProcedureReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_case_investigation_procedure_execution_id', 'decision', 'summary', 'reviewed_by',
        'reviewer_snapshot', 'execution_snapshot', 'reviewed_at', 'fingerprint',
    ];

    protected $casts = [
        'decision' => ComplianceCaseInvestigationProcedureReviewDecision::class,
        'reviewer_snapshot' => 'array', 'execution_snapshot' => 'array', 'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Investigation procedure reviews are immutable.'));
        static::deleting(fn () => throw new \LogicException('Investigation procedure reviews are retained.'));
    }

    protected static function newFactory(): ComplianceCaseInvestigationProcedureReviewFactory
    {
        return ComplianceCaseInvestigationProcedureReviewFactory::new();
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseInvestigationProcedureExecution::class, 'compliance_case_investigation_procedure_execution_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
