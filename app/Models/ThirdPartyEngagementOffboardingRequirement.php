<?php

namespace App\Models;

use App\Enums\ThirdPartyOffboardingCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ThirdPartyEngagementOffboardingRequirement extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_engagement_id', 'version', 'category', 'title', 'acceptance_criteria', 'owner_id', 'due_at', 'required', 'engagement_snapshot', 'defined_by', 'defined_at', 'fingerprint'];

    protected $casts = ['category' => ThirdPartyOffboardingCategory::class, 'due_at' => 'date', 'required' => 'boolean', 'engagement_snapshot' => 'array', 'defined_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Offboarding requirements are append-only.'));
        static::deleting(fn () => throw new LogicException('Offboarding requirements are append-only.'));
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagement::class, 'third_party_engagement_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function definer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'defined_by')->withTrashed();
    }

    public function completions(): HasMany
    {
        return $this->hasMany(ThirdPartyEngagementOffboardingCompletion::class)->orderBy('version');
    }
}
