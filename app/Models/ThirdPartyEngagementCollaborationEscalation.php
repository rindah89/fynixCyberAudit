<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyEngagementCollaborationEscalation extends Model
{
    protected $fillable = ['third_party_engagement_collaboration_request_id', 'third_party_engagement_id', 'vendor_user_id', 'channel', 'notification_ids', 'recipient_snapshots', 'request_snapshot', 'event_snapshot', 'overdue_reminder_snapshot', 'attempted_at', 'delivered_at', 'fingerprint'];

    protected $casts = ['notification_ids' => 'array', 'recipient_snapshots' => 'array', 'request_snapshot' => 'array', 'event_snapshot' => 'array', 'overdue_reminder_snapshot' => 'array', 'attempted_at' => 'datetime', 'delivered_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Collaboration escalation evidence is append-only.'));
        static::deleting(fn () => throw new LogicException('Collaboration escalation evidence is retained governance history.'));
    }

    public function collaborationRequest(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagementCollaborationRequest::class, 'third_party_engagement_collaboration_request_id');
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagement::class, 'third_party_engagement_id');
    }

    public function vendorRecipient(): BelongsTo
    {
        return $this->belongsTo(VendorUser::class, 'vendor_user_id')->withTrashed();
    }
}
