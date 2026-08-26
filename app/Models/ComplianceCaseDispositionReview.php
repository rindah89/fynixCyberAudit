<?php

namespace App\Models;

use App\Enums\ComplianceCaseDispositionDecision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ComplianceCaseDispositionReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_case_retention_classification_id', 'decision', 'summary', 'reviewed_by', 'reviewer_snapshot',
        'classification_snapshot', 'reviewed_at', 'fingerprint',
    ];

    protected $hidden = ['classification_snapshot'];

    protected $casts = [
        'decision' => ComplianceCaseDispositionDecision::class, 'reviewer_snapshot' => 'array',
        'classification_snapshot' => 'array', 'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Governed disposition reviews are immutable.'));
        static::deleting(fn () => throw new LogicException('Governed disposition reviews are retained.'));
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseRetentionClassification::class, 'compliance_case_retention_classification_id');
    }
}
