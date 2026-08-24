<?php

namespace App\Models;

use App\Enums\PolicyAcknowledgementReminderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PolicyAcknowledgementReminder extends Model
{
    protected $fillable = [
        'policy_acknowledgement_assignment_id', 'policy_acknowledgement_campaign_id', 'user_id',
        'type', 'channel', 'notification_id', 'recipient_snapshot', 'campaign_snapshot',
        'attempted_at', 'delivered_at', 'fingerprint',
    ];

    protected $casts = [
        'type' => PolicyAcknowledgementReminderType::class,
        'recipient_snapshot' => 'array',
        'campaign_snapshot' => 'array',
        'attempted_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Policy acknowledgement reminder evidence is append-only through product interfaces.'));
        static::deleting(fn () => throw new LogicException('Policy acknowledgement reminder evidence is append-only through product interfaces.'));
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(PolicyAcknowledgementAssignment::class, 'policy_acknowledgement_assignment_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PolicyAcknowledgementCampaign::class, 'policy_acknowledgement_campaign_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}
