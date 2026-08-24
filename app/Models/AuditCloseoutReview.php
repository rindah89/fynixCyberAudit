<?php

namespace App\Models;

use App\Enums\AuditCloseoutDecision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditCloseoutReview extends Model
{
    use HasFactory;

    protected $fillable = ['audit_closeout_submission_id', 'decision', 'review_summary', 'report_snapshot', 'reviewed_by', 'reviewed_at', 'report_disk', 'report_path', 'report_size', 'report_sha256', 'fingerprint'];

    protected $casts = ['decision' => AuditCloseoutDecision::class, 'report_snapshot' => 'array', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit closeout reviews are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit closeout reviews cannot be deleted.'));
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AuditCloseoutSubmission::class, 'audit_closeout_submission_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
