<?php

namespace App\Models;

use Database\Factories\ThirdPartyCollaborationClosureAcknowledgementReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyCollaborationClosureAcknowledgementReceipt extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_collaboration_closure_acknowledgement_delivery_id', 'third_party_collaboration_closure_acknowledgement_id', 'third_party_engagement_collaboration_request_id', 'user_id', 'recipient_snapshot', 'delivery_snapshot', 'acknowledged_at', 'fingerprint'];

    protected $casts = ['recipient_snapshot' => 'array', 'delivery_snapshot' => 'array', 'acknowledged_at' => 'datetime'];

    protected static function newFactory(): ThirdPartyCollaborationClosureAcknowledgementReceiptFactory
    {
        return ThirdPartyCollaborationClosureAcknowledgementReceiptFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Collaboration closure acknowledgement receipts are immutable.'));
        static::deleting(fn () => throw new LogicException('Collaboration closure acknowledgement receipts are retained governance history.'));
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyCollaborationClosureAcknowledgementDelivery::class, 'third_party_collaboration_closure_acknowledgement_delivery_id');
    }

    public function acknowledgement(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyCollaborationClosureAcknowledgement::class, 'third_party_collaboration_closure_acknowledgement_id');
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
