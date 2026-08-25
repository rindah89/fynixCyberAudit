<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ComplianceCaseEvidenceSubmission extends Model
{
    use HasFactory;

    protected $fillable = ['compliance_case_id', 'version', 'summary', 'case_snapshot', 'latest_event_snapshot', 'evidence_manifest', 'recorded_by', 'actor_snapshot', 'recorded_at', 'fingerprint'];

    protected $casts = ['case_snapshot' => 'array', 'latest_event_snapshot' => 'array', 'evidence_manifest' => 'array', 'actor_snapshot' => 'array', 'recorded_at' => 'datetime'];

    protected $hidden = ['evidence_manifest'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Compliance-case evidence submissions are append-only.'));
        static::deleting(fn () => throw new LogicException('Compliance-case evidence submissions are retained governance history.'));
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ComplianceCaseEvidenceFile::class);
    }
}
