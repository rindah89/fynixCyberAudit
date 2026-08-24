<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PolicyAcknowledgement extends Model
{
    use HasFactory;

    protected $fillable = ['policy_acknowledgement_assignment_id', 'acknowledged_by', 'statement', 'comment', 'client_reference', 'campaign_snapshot', 'policy_snapshot', 'policy_fingerprint', 'acknowledged_at'];

    protected $casts = ['campaign_snapshot' => 'array', 'policy_snapshot' => 'array', 'acknowledged_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Policy acknowledgements are immutable.'));
        static::deleting(fn () => throw new LogicException('Policy acknowledgements are immutable.'));
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(PolicyAcknowledgementAssignment::class, 'policy_acknowledgement_assignment_id');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by')->withTrashed();
    }
}
