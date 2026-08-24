<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ThirdPartyCollaborationExtension extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_engagement_collaboration_request_id', 'version', 'proposed_due_at', 'reason', 'recipient_vendor_user_id', 'recipient_snapshot', 'request_snapshot', 'current_due_context', 'requested_at', 'fingerprint'];

    protected $casts = ['proposed_due_at' => 'date', 'recipient_snapshot' => 'array', 'request_snapshot' => 'array', 'current_due_context' => 'array', 'requested_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Collaboration extension requests are append-only.'));
        static::deleting(fn () => throw new LogicException('Collaboration extension requests are retained governance history.'));
    }

    public function collaborationRequest(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagementCollaborationRequest::class, 'third_party_engagement_collaboration_request_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(VendorUser::class, 'recipient_vendor_user_id')->withTrashed();
    }

    public function decision(): HasOne
    {
        return $this->hasOne(ThirdPartyCollaborationExtensionDecision::class, 'third_party_collaboration_extension_id');
    }
}
