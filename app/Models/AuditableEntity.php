<?php

namespace App\Models;

use App\Enums\AuditableEntityCriticality;
use App\Enums\AuditableEntityStatus;
use App\Enums\AuditableEntityType;
use App\Enums\RiskReviewFrequency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class AuditableEntity extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['code', 'name', 'description', 'entity_type', 'owner_id', 'criticality', 'status', 'assessment_frequency', 'next_assessment_at', 'created_by', 'updated_by'];

    protected $casts = [
        'entity_type' => AuditableEntityType::class, 'criticality' => AuditableEntityCriticality::class,
        'status' => AuditableEntityStatus::class, 'assessment_frequency' => RiskReviewFrequency::class,
        'next_assessment_at' => 'date',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    public function risks(): BelongsToMany
    {
        return $this->belongsToMany(Risk::class)->withTimestamps();
    }

    public function controls(): BelongsToMany
    {
        return $this->belongsToMany(Control::class)->withTimestamps();
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(AuditableEntityAssessment::class);
    }

    public function latestAssessment(): HasOne
    {
        return $this->hasOne(AuditableEntityAssessment::class)->latestOfMany('version');
    }

    public function planItems(): HasMany
    {
        return $this->hasMany(AuditPlanItem::class);
    }

    public function getPlanningStatusAttribute(): string
    {
        if ($this->status !== AuditableEntityStatus::Active) {
            return $this->status->value;
        }
        $assessment = $this->relationLoaded('latestAssessment') ? $this->latestAssessment : $this->latestAssessment()->first();
        if (! $assessment) {
            return 'assessment_required';
        }
        if (! $this->assessmentIsCurrent($assessment)) {
            return 'reassessment_required';
        }

        return $this->next_assessment_at->endOfDay()->isPast() ? 'assessment_overdue' : 'assessed';
    }

    public function governanceContext(): array
    {
        $risks = $this->relationLoaded('risks') ? $this->risks->sortBy('id')->values() : $this->risks()->orderBy('risks.id')->get();
        $controls = $this->relationLoaded('controls') ? $this->controls->sortBy('id')->values() : $this->controls()->orderBy('controls.id')->get();

        $context = [
            'entity' => $this->only(['id', 'code', 'name', 'description', 'entity_type', 'owner_id', 'criticality', 'status', 'assessment_frequency']),
            'risks' => $risks->map(fn (Risk $risk): array => $risk->only(['id', 'code', 'name', 'domain', 'status', 'inherent_risk', 'residual_risk', 'is_active', 'updated_at']))->all(),
            'controls' => $controls->map(fn (Control $control): array => $control->only(['id', 'standard_id', 'control_owner_id', 'code', 'title', 'status', 'effectiveness', 'applicability', 'updated_at']))->all(),
        ];

        return json_decode(json_encode($context, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    public function assessmentIsCurrent(AuditableEntityAssessment $assessment): bool
    {
        $context = $this->governanceContext();

        return $context['entity'] === $assessment->entity_snapshot
            && $context['risks'] === $assessment->risk_snapshots
            && $context['controls'] === $assessment->control_snapshots;
    }
}
