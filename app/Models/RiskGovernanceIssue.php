<?php

namespace App\Models;

use App\Enums\GovernanceIssueStatus;
use App\Models\Concerns\HasGovernanceIssueLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskGovernanceIssue extends Model
{
    use HasGovernanceIssueLifecycle;

    protected $fillable = ['risk_id', 'risk_governance_review_id', 'owner_id', 'title', 'description', 'severity', 'status', 'remediation_task_id'];

    protected $casts = ['status' => GovernanceIssueStatus::class];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(RiskGovernanceReview::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function remediationTask(): BelongsTo
    {
        return $this->belongsTo(RemediationTask::class);
    }
}
