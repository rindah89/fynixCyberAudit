<?php

namespace App\Models;

use App\Enums\AiDecisionImpact;
use App\Enums\AiLifecycleStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiSystem extends Model
{
    use HasFactory, SoftDeletes;

    protected $attributes = ['lifecycle_status' => 'proposed', 'criticality' => 'medium'];

    protected $fillable = [
        'owner_id', 'vendor_id', 'application_id', 'code', 'name', 'provider_name', 'model_name',
        'deployment_type', 'lifecycle_status', 'criticality', 'intended_purpose', 'prohibited_uses',
        'human_oversight', 'data_categories', 'next_review_at',
    ];

    protected $casts = [
        'lifecycle_status' => AiLifecycleStatus::class, 'criticality' => AiDecisionImpact::class,
        'data_categories' => 'array', 'next_review_at' => 'date',
    ];

    protected $appends = ['governance_status'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function useCases(): HasMany
    {
        return $this->hasMany(AiUseCase::class);
    }

    public function monitoringReviews(): HasManyThrough
    {
        return $this->hasManyThrough(AiMonitoringReview::class, AiUseCase::class);
    }

    public function getGovernanceStatusAttribute(): string
    {
        if ($this->lifecycle_status === AiLifecycleStatus::Retired) {
            return 'retired';
        }
        if ($this->lifecycle_status === AiLifecycleStatus::Suspended) {
            return 'suspended';
        }
        if ($this->next_review_at->copy()->endOfDay()->isPast()) {
            return 'review_overdue';
        }
        if ($this->relationLoaded('useCases')) {
            $useCases = $this->useCases;
            $useCases->loadMissing(['aiSystem', 'owner', 'latestAssessment', 'latestDecision', 'latestMonitoringReview', 'controls:id', 'risks:id', 'openGovernanceIssues:id,ai_use_case_id,severity']);
            $useCases->loadCount(['controls', 'risks']);
        } else {
            $useCases = $this->useCases()->withGovernanceGraph()->get();
        }
        if ($useCases->isEmpty()) {
            return 'use_case_required';
        }
        $statuses = $useCases->map->governance_status;
        foreach (['suspended', 'action_required', 'monitoring_overdue', 'approval_expired', 'reapproval_required', 'changes_required', 'rejected', 'approval_required', 'risk_mapping_required', 'control_mapping_required', 'assessment_required'] as $status) {
            if ($statuses->contains($status)) {
                return $status;
            }
        }

        return 'governed';
    }
}
