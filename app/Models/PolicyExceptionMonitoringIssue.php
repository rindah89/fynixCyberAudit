<?php

namespace App\Models;

use App\Enums\GovernanceIssueStatus;
use App\Models\Concerns\HasGovernanceIssueLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyExceptionMonitoringIssue extends Model
{
    use HasGovernanceIssueLifecycle;

    protected $fillable = [
        'policy_exception_monitoring_review_id', 'policy_exception_id', 'owner_id',
        'title', 'description', 'severity', 'status', 'remediation_task_id',
    ];

    protected $casts = ['status' => GovernanceIssueStatus::class];

    public function review(): BelongsTo
    {
        return $this->belongsTo(PolicyExceptionMonitoringReview::class, 'policy_exception_monitoring_review_id');
    }

    public function exception(): BelongsTo
    {
        return $this->belongsTo(PolicyException::class, 'policy_exception_id')->withTrashed();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function remediationTask(): BelongsTo
    {
        return $this->belongsTo(RemediationTask::class);
    }
}
