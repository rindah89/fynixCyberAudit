<?php

namespace App\Models;

use App\Enums\ThirdPartyCollaborationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyEngagementCollaborationEvent extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_engagement_collaboration_request_id', 'version', 'status', 'response_text', 'source_reference', 'summary', 'actor_type', 'actor_id', 'actor_snapshot', 'request_snapshot', 'recorded_at', 'fingerprint'];

    protected $casts = ['status' => ThirdPartyCollaborationStatus::class, 'actor_snapshot' => 'array', 'request_snapshot' => 'array', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Collaboration events are append-only.'));
        static::deleting(fn () => throw new LogicException('Collaboration events are append-only.'));
    }

    public function collaborationRequest(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagementCollaborationRequest::class, 'third_party_engagement_collaboration_request_id');
    }
}
