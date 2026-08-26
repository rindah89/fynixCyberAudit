<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ComplianceCaseRetentionClassification extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_case_id', 'version', 'policy_reference', 'classification', 'starts_on', 'ends_on', 'rationale',
        'classified_by', 'classifier_snapshot', 'case_snapshot', 'classified_at', 'fingerprint',
    ];

    protected $hidden = ['case_snapshot'];

    protected $casts = [
        'starts_on' => 'date', 'ends_on' => 'date', 'classifier_snapshot' => 'array', 'case_snapshot' => 'array',
        'classified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Governed retention classifications are immutable.'));
        static::deleting(fn () => throw new LogicException('Governed retention classifications are retained.'));
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class);
    }

    public function disposition(): HasOne
    {
        return $this->hasOne(ComplianceCaseDispositionReview::class);
    }
}
