<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ComplianceCaseEvidenceFile extends Model
{
    protected $fillable = ['compliance_case_evidence_submission_id', 'file_attachment_id', 'data_request_response_id_snapshot', 'response_status_snapshot', 'data_request_id_snapshot', 'audit_id_snapshot', 'linked_by', 'disk_snapshot', 'file_name_snapshot', 'file_path_snapshot', 'file_size_snapshot', 'sha256', 'linked_at'];

    protected $casts = ['linked_at' => 'datetime', 'file_size_snapshot' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Compliance-case evidence files are immutable.'));
        static::deleting(fn () => throw new LogicException('Compliance-case evidence files are retained governance history.'));
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseEvidenceSubmission::class, 'compliance_case_evidence_submission_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(FileAttachment::class, 'file_attachment_id');
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by')->withTrashed();
    }
}
