<?php

namespace App\Models;

use App\Enums\ComplianceCaseInvestigationReportOutcome;
use Database\Factories\ComplianceCaseInvestigationReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ComplianceCaseInvestigationReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_case_id', 'version', 'outcome', 'executive_summary', 'analysis', 'findings', 'recommendations',
        'report_snapshot', 'authored_by', 'author_snapshot', 'authored_at', 'fingerprint',
    ];

    protected $casts = [
        'outcome' => ComplianceCaseInvestigationReportOutcome::class, 'report_snapshot' => 'array',
        'author_snapshot' => 'array', 'authored_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Investigation reports are immutable.'));
        static::deleting(fn () => throw new \LogicException('Investigation reports are retained.'));
    }

    protected static function newFactory(): ComplianceCaseInvestigationReportFactory
    {
        return ComplianceCaseInvestigationReportFactory::new();
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
        return $this->hasOne(ComplianceCaseInvestigationReportReview::class, 'compliance_case_investigation_report_id');
    }
}
