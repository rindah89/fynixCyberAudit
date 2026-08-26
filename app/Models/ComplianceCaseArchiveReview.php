<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ComplianceCaseArchiveReview extends Model
{
    protected $fillable = [
        'compliance_case_archive_manifest_id', 'decision', 'summary', 'reviewed_by', 'reviewer_snapshot',
        'manifest_snapshot', 'reviewed_at', 'fingerprint',
    ];

    protected $hidden = ['manifest_snapshot'];

    protected $casts = ['reviewer_snapshot' => 'array', 'manifest_snapshot' => 'array', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Governed archive reviews are immutable.'));
        static::deleting(fn () => throw new LogicException('Governed archive reviews are retained.'));
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseArchiveManifest::class, 'compliance_case_archive_manifest_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
