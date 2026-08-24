<?php

namespace App\Models;

use App\Enums\PolicyExceptionDecisionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PolicyExceptionDecision extends Model
{
    use HasFactory;

    protected $fillable = [
        'policy_exception_id', 'version', 'decision', 'decision_summary', 'exception_snapshot',
        'decided_by', 'decided_at', 'fingerprint',
    ];

    protected $casts = [
        'decision' => PolicyExceptionDecisionType::class,
        'exception_snapshot' => 'array',
        'decided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Policy exception decisions are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Policy exception decisions cannot be deleted.'));
    }

    public function exception(): BelongsTo
    {
        return $this->belongsTo(PolicyException::class, 'policy_exception_id')->withTrashed();
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }
}
