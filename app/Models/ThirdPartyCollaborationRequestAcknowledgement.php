<?php

namespace App\Models;

use Database\Factories\ThirdPartyCollaborationRequestAcknowledgementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyCollaborationRequestAcknowledgement extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_engagement_collaboration_request_id', 'latest_event_id', 'recipient_context_fingerprint', 'request_snapshot', 'latest_event_snapshot', 'recipient_context', 'due_context', 'vendor_user_id', 'recipient_snapshot', 'acknowledged_at', 'fingerprint'];

    protected $casts = ['request_snapshot' => 'array', 'latest_event_snapshot' => 'array', 'recipient_context' => 'array', 'due_context' => 'array', 'recipient_snapshot' => 'array', 'acknowledged_at' => 'datetime'];

    protected static function newFactory(): ThirdPartyCollaborationRequestAcknowledgementFactory
    {
        return ThirdPartyCollaborationRequestAcknowledgementFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Collaboration request acknowledgements are immutable.'));
        static::deleting(fn () => throw new LogicException('Collaboration request acknowledgements are retained governance history.'));
    }

    public function collaborationRequest(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagementCollaborationRequest::class, 'third_party_engagement_collaboration_request_id');
    }

    public function latestEvent(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagementCollaborationEvent::class, 'latest_event_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(VendorUser::class, 'vendor_user_id')->withTrashed();
    }
}
