<?php

namespace App\Models;

use App\Enums\ComplianceCaseInvestigationPlanDecision;
use Database\Factories\ComplianceCaseInvestigationPlanReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceCaseInvestigationPlanReview extends Model
{
    use HasFactory;

    protected $fillable = ['compliance_case_investigation_plan_id', 'decision', 'summary', 'reviewed_by', 'reviewer_snapshot', 'plan_snapshot', 'reviewed_at', 'fingerprint'];

    protected $casts = ['decision' => ComplianceCaseInvestigationPlanDecision::class, 'reviewer_snapshot' => 'array', 'plan_snapshot' => 'array', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Investigation plan reviews are immutable.'));
        static::deleting(fn () => throw new \LogicException('Investigation plan reviews are retained.'));
    }

    protected static function newFactory(): ComplianceCaseInvestigationPlanReviewFactory
    {
        return ComplianceCaseInvestigationPlanReviewFactory::new();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseInvestigationPlan::class, 'compliance_case_investigation_plan_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
