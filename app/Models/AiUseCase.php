<?php

namespace App\Models;

use App\Enums\AiDecisionImpact;
use App\Enums\AiGovernanceDecisionType;
use App\Enums\AiMonitoringOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiUseCase extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['ai_system_id', 'owner_id', 'name', 'purpose', 'decision_impact', 'affected_population', 'uses_personal_data', 'uses_sensitive_data', 'automated_decision', 'next_monitoring_at'];

    protected $casts = [
        'decision_impact' => AiDecisionImpact::class, 'uses_personal_data' => 'boolean',
        'uses_sensitive_data' => 'boolean', 'automated_decision' => 'boolean', 'next_monitoring_at' => 'date',
    ];

    protected $appends = ['governance_status'];

    public function aiSystem(): BelongsTo
    {
        return $this->belongsTo(AiSystem::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(AiRiskAssessment::class);
    }

    public function latestAssessment(): HasOne
    {
        return $this->hasOne(AiRiskAssessment::class)->latestOfMany('version');
    }

    public function controls(): BelongsToMany
    {
        return $this->belongsToMany(Control::class, 'ai_use_case_control')->withTimestamps();
    }

    public function risks(): BelongsToMany
    {
        return $this->belongsToMany(Risk::class, 'ai_use_case_risk')->withTimestamps();
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(AiGovernanceDecision::class);
    }

    public function latestDecision(): HasOne
    {
        return $this->hasOne(AiGovernanceDecision::class)->latestOfMany('decided_at');
    }

    public function monitoringReviews(): HasMany
    {
        return $this->hasMany(AiMonitoringReview::class);
    }

    public function latestMonitoringReview(): HasOne
    {
        return $this->hasOne(AiMonitoringReview::class)->latestOfMany('reviewed_at');
    }

    public function governanceIssues(): HasMany
    {
        return $this->hasMany(AiGovernanceIssue::class);
    }

    public function openGovernanceIssues(): HasMany
    {
        return $this->hasMany(AiGovernanceIssue::class)->where('status', '!=', 'closed');
    }

    public function scopeWithGovernanceGraph(Builder $query): Builder
    {
        return $query->with(['aiSystem', 'owner', 'latestAssessment', 'latestDecision', 'latestMonitoringReview', 'controls:id', 'risks:id', 'openGovernanceIssues:id,ai_use_case_id,severity'])->withCount(['controls', 'risks']);
    }

    public function governanceSnapshot(?AiRiskAssessment $assessment = null): array
    {
        $system = $this->relationLoaded('aiSystem') ? $this->aiSystem : $this->aiSystem()->firstOrFail();
        $assessment ??= $this->relationLoaded('latestAssessment') ? $this->latestAssessment : $this->latestAssessment()->firstOrFail();
        $controlIds = $this->relationLoaded('controls') ? $this->controls->pluck('id')->sort()->values()->all() : $this->controls()->orderBy('controls.id')->pluck('controls.id')->all();
        $riskIds = $this->relationLoaded('risks') ? $this->risks->pluck('id')->sort()->values()->all() : $this->risks()->orderBy('risks.id')->pluck('risks.id')->all();
        $systemSnapshot = $system->only(['id', 'owner_id', 'vendor_id', 'application_id', 'provider_name', 'model_name', 'deployment_type', 'lifecycle_status', 'criticality', 'intended_purpose', 'prohibited_uses', 'human_oversight', 'data_categories']);
        $useCaseSnapshot = $this->only(['id', 'owner_id', 'name', 'purpose', 'decision_impact', 'affected_population', 'uses_personal_data', 'uses_sensitive_data', 'automated_decision']);
        $payload = compact('controlIds', 'riskIds', 'systemSnapshot', 'useCaseSnapshot') + ['assessment_id' => $assessment->id];

        return $payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))];
    }

    public function getGovernanceStatusAttribute(): string
    {
        $assessment = $this->relationLoaded('latestAssessment') ? $this->latestAssessment : $this->latestAssessment()->first();
        if (! $assessment) {
            return 'assessment_required';
        }
        $controlsCount = array_key_exists('controls_count', $this->attributes) ? $this->controls_count : $this->controls()->count();
        if ($controlsCount === 0) {
            return 'control_mapping_required';
        }
        $risksCount = array_key_exists('risks_count', $this->attributes) ? $this->risks_count : $this->risks()->count();
        if ($risksCount === 0) {
            return 'risk_mapping_required';
        }
        $decision = $this->relationLoaded('latestDecision') ? $this->latestDecision : $this->latestDecision()->first();
        if (! $decision) {
            return 'approval_required';
        }
        if ($decision->governance_fingerprint !== $this->governanceSnapshot($assessment)['fingerprint']) {
            return 'reapproval_required';
        }
        if ($decision->decision !== AiGovernanceDecisionType::Approved) {
            return $decision->decision->value;
        }
        if ($decision->expires_at?->copy()->endOfDay()->isPast()) {
            return 'approval_expired';
        }
        $openIssues = $this->relationLoaded('openGovernanceIssues') ? $this->openGovernanceIssues : $this->openGovernanceIssues()->get();
        if ($openIssues->isNotEmpty()) {
            $hasSuspension = $openIssues->contains('severity', 'critical');

            return $hasSuspension ? 'suspended' : 'action_required';
        }
        $review = $this->relationLoaded('latestMonitoringReview') ? $this->latestMonitoringReview : $this->latestMonitoringReview()->first();
        if ($review?->outcome === AiMonitoringOutcome::Suspended) {
            return 'suspended';
        }
        if ($review?->outcome === AiMonitoringOutcome::NeedsAction) {
            return 'action_required';
        }
        if ($this->next_monitoring_at?->copy()->endOfDay()->isPast()) {
            return 'monitoring_overdue';
        }

        return 'approved';
    }
}
