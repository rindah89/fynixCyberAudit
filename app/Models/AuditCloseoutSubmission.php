<?php

namespace App\Models;

use App\Enums\AuditCloseoutDecision;
use App\Enums\AuditOpinion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class AuditCloseoutSubmission extends Model
{
    use HasFactory;

    protected $fillable = ['audit_id', 'version', 'opinion', 'executive_summary', 'scope_limitations', 'significant_matters', 'recommendations_summary', 'audit_snapshot', 'engagement_baseline_snapshot', 'audit_item_snapshots', 'data_request_snapshots', 'audit_procedure_snapshots', 'audit_effort_snapshots', 'submitted_by', 'submitted_at', 'fingerprint'];

    protected $casts = ['opinion' => AuditOpinion::class, 'audit_snapshot' => 'array', 'engagement_baseline_snapshot' => 'array', 'audit_item_snapshots' => 'array', 'data_request_snapshots' => 'array', 'audit_procedure_snapshots' => 'array', 'audit_effort_snapshots' => 'array', 'submitted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit closeout submissions are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit closeout submissions cannot be deleted.'));
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by')->withTrashed();
    }

    public function review(): HasOne
    {
        return $this->hasOne(AuditCloseoutReview::class);
    }

    public static function freezesAudit(int $auditId): bool
    {
        return self::query()->where('audit_id', $auditId)->where(function ($query): void {
            $query->whereDoesntHave('review')->orWhereHas('review', fn ($review) => $review->where('decision', AuditCloseoutDecision::Approved));
        })->exists();
    }
}
