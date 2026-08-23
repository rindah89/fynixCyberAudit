<?php

namespace App\Models;

use App\Enums\ResilienceCriticality;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusinessService extends Model
{
    use HasFactory, SoftDeletes;

    protected $attributes = ['criticality' => 'medium', 'status' => 'active'];

    protected $fillable = ['owner_id', 'code', 'name', 'description', 'criticality', 'status'];

    protected $casts = ['criticality' => ResilienceCriticality::class];

    protected $appends = ['readiness_status', 'latest_exercise_outcome'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function impactAnalyses(): HasMany
    {
        return $this->hasMany(BusinessImpactAnalysis::class);
    }

    public function latestApprovedImpactAnalysis(): HasOne
    {
        return $this->hasOne(BusinessImpactAnalysis::class)->whereNotNull('approved_at')->latestOfMany('version');
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(BusinessServiceDependency::class);
    }

    public function recoveryPlans(): HasMany
    {
        return $this->hasMany(RecoveryPlan::class);
    }

    public function resilienceIssues(): HasMany
    {
        return $this->hasMany(ResilienceIssue::class);
    }

    public function latestApprovedRecoveryPlan(): HasOne
    {
        return $this->hasOne(RecoveryPlan::class)->where('status', 'approved')->latestOfMany('version');
    }

    public function getReadinessStatusAttribute(): string
    {
        if ($this->status !== 'active') {
            return 'inactive';
        }
        $issues = $this->relationLoaded('resilienceIssues')
            ? $this->resilienceIssues
            : $this->resilienceIssues()->get();
        if ($issues->contains(fn (ResilienceIssue $issue): bool => $issue->status->value !== 'closed')) {
            return 'action_required';
        }
        $analysis = $this->relationLoaded('latestApprovedImpactAnalysis')
            ? $this->latestApprovedImpactAnalysis
            : $this->latestApprovedImpactAnalysis()->first();
        if (! $analysis) {
            return 'impact_analysis_required';
        }
        $plan = $this->relationLoaded('latestApprovedRecoveryPlan')
            ? $this->latestApprovedRecoveryPlan
            : $this->latestApprovedRecoveryPlan()->with('latestCompletedExercise')->first();
        if (! $plan) {
            return 'recovery_plan_required';
        }
        if ($plan->review_due_at->copy()->endOfDay()->isPast()) {
            return 'plan_review_overdue';
        }
        $exercise = $plan->relationLoaded('latestCompletedExercise')
            ? $plan->latestCompletedExercise
            : $plan->latestCompletedExercise()->first();
        if (! $exercise) {
            return 'exercise_required';
        }

        if ($exercise->outcome->value !== 'passed' && ! $issues->contains('recovery_exercise_id', $exercise->id)) {
            return 'action_required';
        }

        return 'ready';
    }

    public function getLatestExerciseOutcomeAttribute(): ?string
    {
        $plan = $this->relationLoaded('latestApprovedRecoveryPlan')
            ? $this->latestApprovedRecoveryPlan
            : $this->latestApprovedRecoveryPlan()->with('latestCompletedExercise')->first();
        $exercise = $plan && $plan->relationLoaded('latestCompletedExercise')
            ? $plan->latestCompletedExercise
            : $plan?->latestCompletedExercise()->first();
        $outcome = $exercise?->outcome;

        return $outcome?->value;
    }
}
