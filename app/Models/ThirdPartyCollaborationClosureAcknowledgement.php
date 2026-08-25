<?php

namespace App\Models;

use Database\Factories\ThirdPartyCollaborationClosureAcknowledgementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyCollaborationClosureAcknowledgement extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_collaboration_request_closure_id', 'third_party_collaboration_closure_delivery_id', 'third_party_engagement_collaboration_request_id', 'vendor_user_id', 'recipient_snapshot', 'closure_snapshot', 'delivery_snapshot', 'acknowledged_at', 'fingerprint'];

    protected $casts = ['recipient_snapshot' => 'array', 'closure_snapshot' => 'array', 'delivery_snapshot' => 'array', 'acknowledged_at' => 'datetime'];

    protected $hidden = ['id', 'third_party_collaboration_request_closure_id', 'third_party_collaboration_closure_delivery_id', 'third_party_engagement_collaboration_request_id', 'vendor_user_id', 'recipient_snapshot', 'closure_snapshot', 'delivery_snapshot'];

    protected static function newFactory(): ThirdPartyCollaborationClosureAcknowledgementFactory
    {
        return ThirdPartyCollaborationClosureAcknowledgementFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Collaboration closure acknowledgements are immutable.'));
        static::deleting(fn () => throw new LogicException('Collaboration closure acknowledgements are retained governance history.'));
    }

    public function closure(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyCollaborationRequestClosure::class, 'third_party_collaboration_request_closure_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyCollaborationClosureDelivery::class, 'third_party_collaboration_closure_delivery_id');
    }

    public function collaborationRequest(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagementCollaborationRequest::class, 'third_party_engagement_collaboration_request_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(VendorUser::class, 'vendor_user_id')->withTrashed();
    }
}
