<?php

namespace App\Models;

use App\Enums\ComplianceCaseInvestigationReportDecision;
use Database\Factories\ComplianceCaseInvestigationReportReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceCaseInvestigationReportReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_case_investigation_report_id', 'decision', 'summary', 'reviewed_by',
        'reviewer_snapshot', 'report_snapshot', 'reviewed_at', 'fingerprint',
    ];

    protected $casts = [
        'decision' => ComplianceCaseInvestigationReportDecision::class, 'reviewer_snapshot' => 'array',
        'report_snapshot' => 'array', 'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Investigation report reviews are immutable.'));
        static::deleting(fn () => throw new \LogicException('Investigation report reviews are retained.'));
    }

    protected static function newFactory(): ComplianceCaseInvestigationReportReviewFactory
    {
        return ComplianceCaseInvestigationReportReviewFactory::new();
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseInvestigationReport::class, 'compliance_case_investigation_report_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
