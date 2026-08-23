<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class GovernanceIssueClosureEvidence extends Model
{
    protected $table = 'governance_issue_closure_evidence';

    protected $fillable = [
        'governance_issue_lifecycle_id', 'governance_issue_transition_id', 'file_attachment_id',
        'data_request_response_id_snapshot', 'response_status_snapshot', 'data_request_id_snapshot', 'audit_id_snapshot',
        'linked_by', 'disk_snapshot', 'file_name_snapshot', 'file_path_snapshot',
        'file_size_snapshot', 'sha256', 'linked_at',
    ];

    protected $casts = ['linked_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Governance issue closure evidence is append-only through product interfaces.'));
        static::deleting(fn () => throw new LogicException('Governance issue closure evidence is append-only through product interfaces.'));
    }

    public function lifecycle(): BelongsTo
    {
        return $this->belongsTo(GovernanceIssueLifecycle::class, 'governance_issue_lifecycle_id');
    }

    public function transition(): BelongsTo
    {
        return $this->belongsTo(GovernanceIssueTransition::class, 'governance_issue_transition_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(FileAttachment::class, 'file_attachment_id');
    }

    public function dataRequestResponseSnapshot(): BelongsTo
    {
        return $this->belongsTo(DataRequestResponse::class, 'data_request_response_id_snapshot');
    }

    public function dataRequestSnapshot(): BelongsTo
    {
        return $this->belongsTo(DataRequest::class, 'data_request_id_snapshot');
    }

    public function auditSnapshot(): BelongsTo
    {
        return $this->belongsTo(Audit::class, 'audit_id_snapshot');
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }
}
