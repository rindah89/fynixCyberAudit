<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyCollaborationRecipientReassignment extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_engagement_collaboration_request_id', 'version', 'from_vendor_user_id', 'to_vendor_user_id', 'from_recipient_snapshot', 'to_recipient_snapshot', 'prior_recipient_context', 'request_snapshot', 'reason', 'reassigned_by', 'actor_snapshot', 'reassigned_at', 'fingerprint'];

    protected $casts = ['from_recipient_snapshot' => 'array', 'to_recipient_snapshot' => 'array', 'prior_recipient_context' => 'array', 'request_snapshot' => 'array', 'actor_snapshot' => 'array', 'reassigned_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Collaboration recipient reassignments are append-only.'));
        static::deleting(fn () => throw new LogicException('Collaboration recipient reassignments are retained governance history.'));
    }

    public function collaborationRequest(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagementCollaborationRequest::class, 'third_party_engagement_collaboration_request_id');
    }

    public function fromRecipient(): BelongsTo
    {
        return $this->belongsTo(VendorUser::class, 'from_vendor_user_id')->withTrashed();
    }

    public function toRecipient(): BelongsTo
    {
        return $this->belongsTo(VendorUser::class, 'to_vendor_user_id')->withTrashed();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reassigned_by')->withTrashed();
    }
}
