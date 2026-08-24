<?php

namespace App\Models;

use App\Enums\GovernanceIssueStatus;
use App\Models\Concerns\HasGovernanceIssueLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyCollaborationEscalationIssue extends Model
{
    use HasGovernanceIssueLifecycle;

    protected $fillable = [
        'third_party_engagement_collaboration_escalation_id', 'third_party_engagement_collaboration_escalation_action_id',
        'third_party_engagement_id', 'owner_id', 'opened_by', 'title', 'description', 'severity', 'status',
        'remediation_task_id', 'source_snapshot', 'opened_at', 'fingerprint',
    ];

    protected $casts = ['status' => GovernanceIssueStatus::class, 'source_snapshot' => 'array', 'opened_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Collaboration escalation issue evidence is immutable outside its governed lifecycle.'));
    }

    public function escalation(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagementCollaborationEscalation::class, 'third_party_engagement_collaboration_escalation_id');
    }

    public function acknowledgement(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagementCollaborationEscalationAction::class, 'third_party_engagement_collaboration_escalation_action_id');
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagement::class, 'third_party_engagement_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by')->withTrashed();
    }

    public function remediationTask(): BelongsTo
    {
        return $this->belongsTo(RemediationTask::class);
    }
}
