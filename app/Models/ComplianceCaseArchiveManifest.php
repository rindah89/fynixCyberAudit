<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ComplianceCaseArchiveManifest extends Model
{
    use HasFactory;

    protected $hidden = ['archive_disk', 'archive_path', 'source_fingerprints'];

    protected $fillable = [
        'compliance_case_id', 'compliance_case_closure_report_id', 'version', 'source_fingerprints',
        'archive_disk', 'archive_path', 'archive_size', 'archive_sha256', 'schema_version',
        'generated_by', 'generator_snapshot', 'generated_at', 'fingerprint',
    ];

    protected $casts = [
        'source_fingerprints' => 'array', 'generator_snapshot' => 'array', 'generated_at' => 'datetime',
        'archive_size' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Governed archive manifests are immutable.'));
        static::deleting(fn () => throw new LogicException('Governed archive manifests are retained.'));
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class);
    }

    public function closureReport(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseClosureReport::class, 'compliance_case_closure_report_id');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by')->withTrashed();
    }

    public function review(): HasOne
    {
        return $this->hasOne(ComplianceCaseArchiveReview::class);
    }
}
