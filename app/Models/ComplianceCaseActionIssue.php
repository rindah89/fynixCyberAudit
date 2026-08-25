<?php

namespace App\Models;

use App\Enums\GovernanceIssueStatus;
use App\Models\Concerns\HasGovernanceIssueLifecycle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ComplianceCaseActionIssue extends Model
{
    use HasFactory;
    use HasGovernanceIssueLifecycle;

    protected $fillable = [
        'compliance_case_id', 'compliance_case_event_id', 'owner_id', 'opened_by', 'title', 'description',
        'severity', 'status', 'remediation_task_id', 'source_snapshot', 'opened_at', 'fingerprint',
    ];

    protected $casts = ['status' => GovernanceIssueStatus::class, 'source_snapshot' => 'array', 'opened_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $issue): void {
            if ($issue->isDirty(['compliance_case_id', 'compliance_case_event_id', 'owner_id', 'opened_by', 'title', 'description', 'severity', 'source_snapshot', 'opened_at', 'fingerprint'])) {
                throw new LogicException('Compliance case action issue evidence is immutable.');
            }
        });
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseEvent::class, 'compliance_case_event_id');
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
