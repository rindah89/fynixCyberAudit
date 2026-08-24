<?php

namespace App\Models;

use App\Enums\ThirdPartyEngagementStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyEngagementEvent extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_engagement_id', 'version', 'from_status', 'to_status', 'summary', 'engagement_snapshot', 'recorded_by', 'recorded_at', 'fingerprint'];

    protected $casts = ['from_status' => ThirdPartyEngagementStatus::class, 'to_status' => ThirdPartyEngagementStatus::class, 'engagement_snapshot' => 'array', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Third-party engagement events are append-only.'));
        static::deleting(fn () => throw new LogicException('Third-party engagement events are append-only.'));
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagement::class, 'third_party_engagement_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }
}
