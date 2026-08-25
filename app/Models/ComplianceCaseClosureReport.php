<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ComplianceCaseClosureReport extends Model
{
    use HasFactory;

    protected $hidden = ['report_snapshot', 'report_disk', 'report_path'];

    protected $fillable = [
        'compliance_case_id', 'version', 'report_snapshot', 'generated_by', 'generator_snapshot', 'generated_at',
        'report_disk', 'report_path', 'report_size', 'report_sha256', 'fingerprint',
    ];

    protected $casts = [
        'report_snapshot' => 'array', 'generator_snapshot' => 'array', 'generated_at' => 'datetime', 'report_size' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Compliance case closure reports are append-only.'));
        static::deleting(fn () => throw new LogicException('Compliance case closure reports are append-only.'));
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by')->withTrashed();
    }

    public function review(): HasOne
    {
        return $this->hasOne(ComplianceCaseClosureReportReview::class);
    }
}
