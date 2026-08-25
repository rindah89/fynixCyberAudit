<?php

namespace App\Models;

use Database\Factories\ComplianceCaseInvestigationPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ComplianceCaseInvestigationPlan extends Model
{
    use HasFactory;

    protected $fillable = ['compliance_case_id', 'version', 'objectives', 'scope', 'procedures', 'target_completion_at', 'authored_by', 'author_snapshot', 'case_snapshot', 'rationale', 'submitted_at', 'fingerprint'];

    protected $casts = ['objectives' => 'array', 'procedures' => 'array', 'target_completion_at' => 'date', 'author_snapshot' => 'array', 'case_snapshot' => 'array', 'submitted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Investigation plans are immutable.'));
        static::deleting(fn () => throw new \LogicException('Investigation plans are retained.'));
    }

    protected static function newFactory(): ComplianceCaseInvestigationPlanFactory
    {
        return ComplianceCaseInvestigationPlanFactory::new();
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authored_by')->withTrashed();
    }

    public function review(): HasOne
    {
        return $this->hasOne(ComplianceCaseInvestigationPlanReview::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(ComplianceCaseInvestigationProcedureExecution::class, 'compliance_case_investigation_plan_id')->orderBy('procedure_index');
    }
}
