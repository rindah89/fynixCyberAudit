<?php

namespace App\Models;

use App\Enums\ThirdPartyCollaborationTimeliness;
use Database\Factories\ThirdPartyCollaborationRequestClosureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ThirdPartyCollaborationRequestClosure extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_engagement_collaboration_request_id', 'accepted_event_id', 'request_snapshot', 'accepted_event_snapshot', 'recipient_context', 'due_context', 'escalation_snapshot', 'response_recorded_at', 'timeliness_status', 'days_late', 'calendar_timezone', 'timeliness_fingerprint', 'fingerprint_version', 'summary', 'closed_by', 'actor_snapshot', 'closed_at', 'fingerprint'];

    protected $casts = ['request_snapshot' => 'array', 'accepted_event_snapshot' => 'array', 'recipient_context' => 'array', 'due_context' => 'array', 'escalation_snapshot' => 'array', 'response_recorded_at' => 'datetime', 'timeliness_status' => ThirdPartyCollaborationTimeliness::class, 'days_late' => 'integer', 'actor_snapshot' => 'array', 'closed_at' => 'datetime'];

    protected static function newFactory(): ThirdPartyCollaborationRequestClosureFactory
    {
        return ThirdPartyCollaborationRequestClosureFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Collaboration request closures are immutable.'));
        static::deleting(fn () => throw new LogicException('Collaboration request closures are retained governance history.'));
    }

    public function collaborationRequest(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagementCollaborationRequest::class, 'third_party_engagement_collaboration_request_id');
    }

    public function acceptedEvent(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagementCollaborationEvent::class, 'accepted_event_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(ThirdPartyCollaborationClosureDelivery::class, 'third_party_collaboration_request_closure_id');
    }
}
