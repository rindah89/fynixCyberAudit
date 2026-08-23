<?php

namespace App\Models;

use App\Enums\GovernanceIssueStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class GovernanceIssueTransition extends Model
{
    protected $fillable = ['governance_issue_lifecycle_id', 'from_status', 'to_status', 'transitioned_by', 'rationale', 'remediation_task_id_snapshot', 'remediation_task_snapshot', 'verification_summary_snapshot', 'evidence_reference', 'transitioned_at'];

    protected $casts = ['from_status' => GovernanceIssueStatus::class, 'to_status' => GovernanceIssueStatus::class, 'remediation_task_snapshot' => 'array', 'transitioned_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Governance issue transitions are append-only through product interfaces.'));
        static::deleting(fn () => throw new LogicException('Governance issue transitions are append-only through product interfaces.'));
    }

    public function lifecycle(): BelongsTo
    {
        return $this->belongsTo(GovernanceIssueLifecycle::class, 'governance_issue_lifecycle_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transitioned_by');
    }

    public function closureEvidence(): HasMany
    {
        return $this->hasMany(GovernanceIssueClosureEvidence::class, 'governance_issue_transition_id');
    }
}
