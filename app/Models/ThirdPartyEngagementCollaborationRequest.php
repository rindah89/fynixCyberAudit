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

    protected $appends = ['effective_due_at'];

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

    public function extensions(): HasMany
    {
        return $this->hasMany(ThirdPartyCollaborationExtension::class, 'third_party_engagement_collaboration_request_id')->orderBy('version');
    }

    public function effectiveDueContext(): array
    {
        $extensions = $this->relationLoaded('extensions')
            ? $this->extensions
            : $this->extensions()->with('decision')->get();
        $approved = $extensions->filter(fn (ThirdPartyCollaborationExtension $extension): bool => $extension->decision?->decision?->value === 'approved')->last();

        return $approved
            ? ['due_at' => $approved->proposed_due_at->toDateString(), 'fingerprint' => $approved->decision->fingerprint, 'extension_id' => $approved->id, 'decision_id' => $approved->decision->id]
            : ['due_at' => $this->due_at->toDateString(), 'fingerprint' => $this->fingerprint, 'extension_id' => null, 'decision_id' => null];
    }

    public function getEffectiveDueAtAttribute(): string
    {
        return $this->effectiveDueContext()['due_at'];
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
