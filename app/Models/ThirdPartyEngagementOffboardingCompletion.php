<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyEngagementOffboardingCompletion extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_engagement_offboarding_requirement_id', 'version', 'completion_summary', 'source_reference', 'requirement_snapshot', 'completed_by', 'completed_at', 'fingerprint'];

    protected $casts = ['requirement_snapshot' => 'array', 'completed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Offboarding completions are append-only.'));
        static::deleting(fn () => throw new LogicException('Offboarding completions are append-only.'));
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagementOffboardingRequirement::class, 'third_party_engagement_offboarding_requirement_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by')->withTrashed();
    }
}
