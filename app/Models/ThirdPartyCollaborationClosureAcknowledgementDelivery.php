<?php

namespace App\Models;

use Database\Factories\ThirdPartyCollaborationClosureAcknowledgementDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyCollaborationClosureAcknowledgementDelivery extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_collaboration_closure_acknowledgement_id', 'third_party_collaboration_request_closure_id', 'third_party_engagement_collaboration_request_id', 'user_id', 'accountability_roles', 'recipient_snapshot', 'acknowledgement_snapshot', 'channel', 'notification_id', 'attempted_at', 'delivered_at', 'fingerprint'];

    protected $casts = ['accountability_roles' => 'array', 'recipient_snapshot' => 'array', 'acknowledgement_snapshot' => 'array', 'attempted_at' => 'datetime', 'delivered_at' => 'datetime'];

    protected static function newFactory(): ThirdPartyCollaborationClosureAcknowledgementDeliveryFactory
    {
        return ThirdPartyCollaborationClosureAcknowledgementDeliveryFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Collaboration closure acknowledgement deliveries are immutable.'));
        static::deleting(fn () => throw new LogicException('Collaboration closure acknowledgement deliveries are retained governance history.'));
    }

    public function acknowledgement(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyCollaborationClosureAcknowledgement::class, 'third_party_collaboration_closure_acknowledgement_id');
    }

    public function closure(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyCollaborationRequestClosure::class, 'third_party_collaboration_request_closure_id');
    }

    public function collaborationRequest(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagementCollaborationRequest::class, 'third_party_engagement_collaboration_request_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}
