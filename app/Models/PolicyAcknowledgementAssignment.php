<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class PolicyAcknowledgementAssignment extends Model
{
    use HasFactory;

    protected $fillable = ['policy_acknowledgement_campaign_id', 'user_id', 'assigned_at'];

    protected $casts = ['assigned_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Policy acknowledgement assignments are immutable.'));
        static::deleting(fn () => throw new LogicException('Policy acknowledgement assignments are immutable.'));
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PolicyAcknowledgementCampaign::class, 'policy_acknowledgement_campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function acknowledgement(): HasOne
    {
        return $this->hasOne(PolicyAcknowledgement::class);
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(PolicyAcknowledgementDelivery::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(PolicyAcknowledgementReminder::class);
    }

    public function getAcknowledgementStatusAttribute(): string
    {
        $acknowledgement = $this->relationLoaded('acknowledgement') ? $this->acknowledgement : $this->acknowledgement()->first();
        if ($acknowledgement) {
            return 'acknowledged';
        }
        if ($this->campaign->closed_at) {
            return 'closed_unacknowledged';
        }

        return $this->campaign->due_at->isPast() ? 'overdue' : 'pending';
    }
}
