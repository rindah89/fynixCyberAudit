<?php

namespace App\Models;

use Database\Factories\ThirdPartyCollaborationClosureDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyCollaborationClosureDelivery extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_collaboration_request_closure_id', 'third_party_engagement_collaboration_request_id', 'vendor_user_id', 'channel', 'notification_id', 'recipient_snapshot', 'closure_snapshot', 'attempted_at', 'delivered_at', 'fingerprint'];

    protected $casts = ['recipient_snapshot' => 'array', 'closure_snapshot' => 'array', 'attempted_at' => 'datetime', 'delivered_at' => 'datetime'];

    protected $hidden = ['id', 'third_party_collaboration_request_closure_id', 'third_party_engagement_collaboration_request_id', 'vendor_user_id', 'recipient_snapshot', 'closure_snapshot'];

    protected static function newFactory(): ThirdPartyCollaborationClosureDeliveryFactory
    {
        return ThirdPartyCollaborationClosureDeliveryFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Collaboration closure deliveries are immutable.'));
        static::deleting(fn () => throw new LogicException('Collaboration closure deliveries are retained governance history.'));
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
        return $this->belongsTo(VendorUser::class, 'vendor_user_id')->withTrashed();
    }
}
