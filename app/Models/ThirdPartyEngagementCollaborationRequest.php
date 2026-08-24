<?php

namespace App\Models;

use App\Enums\ThirdPartyCollaborationCategory;
use App\Enums\ThirdPartyCollaborationStatus;
use App\Enums\ThirdPartyEngagementStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ThirdPartyEngagementCollaborationRequest extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_engagement_id', 'version', 'category', 'subject', 'request_text', 'recipient_vendor_user_id', 'due_at', 'engagement_snapshot', 'recipient_snapshot', 'opened_by', 'opened_at', 'fingerprint'];

    protected $casts = ['category' => ThirdPartyCollaborationCategory::class, 'due_at' => 'date', 'engagement_snapshot' => 'array', 'recipient_snapshot' => 'array', 'opened_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Collaboration requests are immutable.'));
        static::deleting(fn () => throw new LogicException('Collaboration requests are retained governance history.'));
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagement::class, 'third_party_engagement_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(VendorUser::class, 'recipient_vendor_user_id')->withTrashed();
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(ThirdPartyEngagementCollaborationEvent::class)->orderBy('version');
    }

    public function latestEvent(): HasOne
    {
        return $this->hasOne(ThirdPartyEngagementCollaborationEvent::class)->latestOfMany('version');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(ThirdPartyEngagementCollaborationReminder::class)->orderBy('delivered_at');
    }

    public function escalation(): HasOne
    {
        return $this->hasOne(ThirdPartyEngagementCollaborationEscalation::class);
    }

    public function latestStatus(): ?ThirdPartyCollaborationStatus
    {
        $event = $this->getRelationValue('latestEvent');

        return $event instanceof ThirdPartyEngagementCollaborationEvent ? $event->status : null;
    }

    public function engagementStatus(): ?ThirdPartyEngagementStatus
    {
        $engagement = $this->getRelationValue('engagement');

        return $engagement instanceof ThirdPartyEngagement ? $engagement->status : null;
    }
}
