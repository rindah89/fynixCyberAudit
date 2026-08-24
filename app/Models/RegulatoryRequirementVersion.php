<?php

namespace App\Models;

use App\Enums\RegulatoryChangeType;
use App\Enums\RegulatoryRequirementStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class RegulatoryRequirementVersion extends Model
{
    use HasFactory;

    protected $fillable = ['regulatory_requirement_id', 'version', 'change_type', 'status', 'title', 'requirement_text', 'effective_at', 'expires_at', 'policy_ids', 'control_ids', 'source_snapshot', 'policy_snapshots', 'control_snapshots', 'content_fingerprint', 'published_by', 'published_at'];

    protected $casts = [
        'change_type' => RegulatoryChangeType::class, 'status' => RegulatoryRequirementStatus::class,
        'effective_at' => 'date', 'expires_at' => 'date', 'policy_ids' => 'array', 'control_ids' => 'array',
        'source_snapshot' => 'array', 'policy_snapshots' => 'array', 'control_snapshots' => 'array', 'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Regulatory requirement versions are immutable. Publish a new version instead.'));
        static::deleting(fn () => throw new LogicException('Regulatory requirement versions are immutable.'));
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(RegulatoryRequirement::class, 'regulatory_requirement_id')->withTrashed();
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by')->withTrashed();
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(RegulatoryChangeAssessment::class);
    }

    public function latestAssessment(): HasOne
    {
        return $this->hasOne(RegulatoryChangeAssessment::class)->latestOfMany('assessment_version');
    }
}
