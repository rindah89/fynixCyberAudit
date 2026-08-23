<?php

namespace App\Models;

use App\Enums\GovernanceIssueStatus;
use App\Models\Concerns\HasGovernanceIssueLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResilienceIssue extends Model
{
    use HasGovernanceIssueLifecycle;

    protected $fillable = ['recovery_exercise_id', 'business_service_id', 'owner_id', 'title', 'description', 'severity', 'status', 'due_at', 'remediation_task_id'];

    protected $casts = ['due_at' => 'date', 'status' => GovernanceIssueStatus::class];

    public function recoveryExercise(): BelongsTo
    {
        return $this->belongsTo(RecoveryExercise::class);
    }

    public function businessService(): BelongsTo
    {
        return $this->belongsTo(BusinessService::class);
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
