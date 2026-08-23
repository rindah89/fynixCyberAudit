<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RiskGovernanceReviewEvidence extends Model
{
    protected $table = 'risk_governance_review_evidence';

    protected $fillable = ['risk_governance_review_id', 'file_attachment_id', 'data_request_response_id_snapshot', 'response_status_snapshot', 'data_request_id_snapshot', 'audit_id_snapshot', 'linked_by', 'disk_snapshot', 'file_name_snapshot', 'file_path_snapshot', 'file_size_snapshot', 'sha256', 'linked_at'];

    protected $casts = ['linked_at' => 'datetime', 'file_size_snapshot' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Risk governance review evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Risk governance review evidence is immutable.'));
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(RiskGovernanceReview::class, 'risk_governance_review_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(FileAttachment::class, 'file_attachment_id');
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }
}
