<?php

namespace App\Models;

use App\Enums\AuditFindingFollowUpOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class AuditFindingFollowUp extends Model
{
    use HasFactory;

    protected $fillable = ['audit_finding_remediation_id', 'version', 'outcome', 'summary', 'evidence_reference', 'evidence_manifest', 'handoff_snapshot', 'task_snapshot', 'reviewed_by', 'reviewed_at', 'fingerprint'];

    protected $casts = ['outcome' => AuditFindingFollowUpOutcome::class, 'evidence_manifest' => 'array', 'handoff_snapshot' => 'array', 'task_snapshot' => 'array', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Governed finding follow-ups are immutable.'));
        static::deleting(fn () => throw new LogicException('Governed finding follow-up history cannot be deleted.'));
    }

    public function remediation(): BelongsTo
    {
        return $this->belongsTo(AuditFindingRemediation::class, 'audit_finding_remediation_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(AuditFindingFollowUpEvidence::class);
    }

    public function fingerprintPayload(): array
    {
        return [
            'audit_finding_remediation_id' => $this->audit_finding_remediation_id,
            'version' => $this->version,
            'outcome' => $this->outcome->value,
            'summary' => $this->summary,
            'evidence_reference' => $this->evidence_reference,
            'evidence_manifest' => $this->evidence_manifest,
            'handoff_snapshot' => $this->handoff_snapshot,
            'task_snapshot' => $this->task_snapshot,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at->toIso8601String(),
        ];
    }
}
