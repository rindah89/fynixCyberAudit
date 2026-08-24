<?php

namespace App\Models;

use App\Enums\ThirdPartyCollaborationEscalationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyEngagementCollaborationEscalationAction extends Model
{
    protected $fillable = ['third_party_engagement_collaboration_escalation_id', 'version', 'status', 'summary', 'action_plan', 'target_resolution_at', 'actor_id', 'actor_snapshot', 'escalation_snapshot', 'accepted_event_snapshot', 'recorded_at', 'fingerprint'];

    protected $casts = ['status' => ThirdPartyCollaborationEscalationStatus::class, 'target_resolution_at' => 'date', 'actor_snapshot' => 'array', 'escalation_snapshot' => 'array', 'accepted_event_snapshot' => 'array', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Collaboration escalation actions are append-only.'));
        static::deleting(fn () => throw new LogicException('Collaboration escalation actions are retained governance history.'));
    }

    public function escalation(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagementCollaborationEscalation::class, 'third_party_engagement_collaboration_escalation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }
}
