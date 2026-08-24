<?php

namespace App\Models;

use Database\Factories\ThirdPartyCollaborationRequestCancellationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyCollaborationRequestCancellation extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_engagement_collaboration_request_id', 'latest_event_id', 'request_snapshot', 'latest_event_snapshot', 'recipient_context', 'due_context', 'reason', 'cancelled_by', 'actor_snapshot', 'cancelled_at', 'fingerprint'];

    protected $casts = ['request_snapshot' => 'array', 'latest_event_snapshot' => 'array', 'recipient_context' => 'array', 'due_context' => 'array', 'actor_snapshot' => 'array', 'cancelled_at' => 'datetime'];

    protected static function newFactory(): ThirdPartyCollaborationRequestCancellationFactory
    {
        return ThirdPartyCollaborationRequestCancellationFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Collaboration request cancellations are immutable.'));
        static::deleting(fn () => throw new LogicException('Collaboration request cancellations are retained governance history.'));
    }

    public function collaborationRequest(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagementCollaborationRequest::class, 'third_party_engagement_collaboration_request_id');
    }

    public function latestEvent(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagementCollaborationEvent::class, 'latest_event_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }
}
