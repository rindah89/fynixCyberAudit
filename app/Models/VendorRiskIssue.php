<?php

namespace App\Models;

use App\Enums\GovernanceIssueStatus;
use App\Models\Concerns\HasGovernanceIssueLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorRiskIssue extends Model
{
    use HasGovernanceIssueLifecycle;

    protected $fillable = ['vendor_id', 'vendor_risk_review_id', 'owner_id', 'title', 'description', 'severity', 'status', 'remediation_task_id'];

    protected $casts = ['status' => GovernanceIssueStatus::class];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(VendorRiskReview::class, 'vendor_risk_review_id');
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
