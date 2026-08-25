<?php

namespace App\Models;

use App\Enums\ComplianceCaseClosureReportReviewDecision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ComplianceCaseClosureReportReview extends Model
{
    use HasFactory;

    protected $hidden = ['closure_report_snapshot'];

    protected $fillable = [
        'compliance_case_closure_report_id', 'decision', 'summary', 'reviewed_by', 'reviewer_snapshot',
        'closure_report_snapshot', 'reviewed_at', 'fingerprint',
    ];

    protected $casts = [
        'decision' => ComplianceCaseClosureReportReviewDecision::class,
        'reviewer_snapshot' => 'array', 'closure_report_snapshot' => 'array', 'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Compliance case closure-report reviews are append-only.'));
        static::deleting(fn () => throw new LogicException('Compliance case closure-report reviews are append-only.'));
    }

    public function closureReport(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseClosureReport::class, 'compliance_case_closure_report_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
