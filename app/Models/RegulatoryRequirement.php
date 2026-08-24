<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class RegulatoryRequirement extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['regulatory_source_id', 'code', 'owner_id', 'created_by'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(RegulatorySource::class, 'regulatory_source_id')->withTrashed();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RegulatoryRequirementVersion::class);
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(RegulatoryRequirementVersion::class)->latestOfMany('version');
    }

    public function assessments(): HasManyThrough
    {
        return $this->hasManyThrough(
            RegulatoryChangeAssessment::class,
            RegulatoryRequirementVersion::class,
            'regulatory_requirement_id',
            'regulatory_requirement_version_id',
        );
    }

    public function getGovernanceStatusAttribute(): string
    {
        $version = $this->relationLoaded('latestVersion') ? $this->latestVersion : $this->latestVersion()->first();
        if (! $version) {
            return 'version_required';
        }
        if ($version->status->value === 'repealed') {
            return 'repealed';
        }
        if ($version->status->value === 'superseded') {
            return 'superseded';
        }
        if ($version->expires_at?->endOfDay()->isPast()) {
            return 'expired';
        }
        $assessment = $version->relationLoaded('latestAssessment') ? $version->latestAssessment : $version->latestAssessment()->first();
        if (! $assessment) {
            return 'assessment_required';
        }
        if ($assessment->applicability->value === 'not_applicable') {
            return 'not_applicable';
        }
        if ($assessment->applicability->value === 'under_review') {
            return $assessment->action_due_at?->endOfDay()->isPast() ? 'review_overdue' : 'under_review';
        }

        return $assessment->action_due_at?->endOfDay()->isPast() ? 'action_overdue' : 'applicable';
    }
}
