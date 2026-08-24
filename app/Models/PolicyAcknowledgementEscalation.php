<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PolicyAcknowledgementEscalation extends Model
{
    protected $fillable = [
        'policy_acknowledgement_assignment_id', 'policy_acknowledgement_campaign_id', 'assigned_user_id',
        'escalated_to_user_id', 'channel', 'notification_id', 'assignment_snapshot', 'recipient_snapshot',
        'campaign_snapshot', 'attempted_at', 'delivered_at', 'fingerprint',
    ];

    protected $casts = [
        'assignment_snapshot' => 'array', 'recipient_snapshot' => 'array', 'campaign_snapshot' => 'array',
        'attempted_at' => 'datetime', 'delivered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Policy acknowledgement escalation evidence is append-only through product interfaces.'));
        static::deleting(fn () => throw new LogicException('Policy acknowledgement escalation evidence is append-only through product interfaces.'));
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(PolicyAcknowledgementAssignment::class, 'policy_acknowledgement_assignment_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PolicyAcknowledgementCampaign::class, 'policy_acknowledgement_campaign_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id')->withTrashed();
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_to_user_id')->withTrashed();
    }
}
