<?php

namespace App\Models;

use App\Enums\AuditWorkpaperReviewDecision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditWorkpaperReview extends Model
{
    use HasFactory;

    protected $fillable = ['audit_procedure_execution_id', 'decision', 'review_summary', 'execution_snapshot', 'reviewed_by', 'reviewed_at', 'fingerprint'];

    protected $casts = ['decision' => AuditWorkpaperReviewDecision::class, 'execution_snapshot' => 'array', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit workpaper reviews are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit workpaper review history cannot be deleted.'));
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(AuditProcedureExecution::class, 'audit_procedure_execution_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
