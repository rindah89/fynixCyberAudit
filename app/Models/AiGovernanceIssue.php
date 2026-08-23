<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGovernanceIssue extends Model
{
    protected $fillable = ['ai_use_case_id', 'ai_monitoring_review_id', 'owner_id', 'title', 'description', 'severity', 'status', 'remediation_task_id'];

    public function useCase(): BelongsTo
    {
        return $this->belongsTo(AiUseCase::class, 'ai_use_case_id');
    }

    public function monitoringReview(): BelongsTo
    {
        return $this->belongsTo(AiMonitoringReview::class);
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
