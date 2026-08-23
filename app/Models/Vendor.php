<?php

namespace App\Models;

use App\Enums\ThirdPartyRiskDecisionType;
use App\Enums\VendorRiskRating;
use App\Enums\VendorStatus;
use App\Mcp\Traits\HasMcpSupport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Vendor extends Model
{
    use HasFactory, HasMcpSupport, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'url',
        'logo',
        'vendor_manager_id',
        'contact_name',
        'contact_email',
        'contact_phone',
        'address',
        'status',
        'risk_rating',
        'risk_score',
        'risk_score_calculated_at',
        'notes',
    ];

    protected $casts = [
        'status' => VendorStatus::class,
        'risk_rating' => VendorRiskRating::class,
        'logo' => 'array',
        'risk_score' => 'integer',
        'risk_score_calculated_at' => 'datetime',
    ];

    public function vendorManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendor_manager_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function surveys(): HasMany
    {
        return $this->hasMany(Survey::class);
    }

    public function vendorUsers(): HasMany
    {
        return $this->hasMany(VendorUser::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VendorDocument::class);
    }

    public function implementations(): BelongsToMany
    {
        return $this->belongsToMany(Implementation::class)
            ->withTimestamps();
    }

    public function riskAssessments(): HasMany
    {
        return $this->hasMany(VendorRiskAssessment::class);
    }

    public function latestRiskAssessment(): HasOne
    {
        return $this->hasOne(VendorRiskAssessment::class)->latestOfMany('version');
    }

    public function risks(): BelongsToMany
    {
        return $this->belongsToMany(Risk::class, 'vendor_risk')->withTimestamps();
    }

    public function riskDecisions(): HasMany
    {
        return $this->hasMany(VendorRiskDecision::class);
    }

    public function latestRiskDecision(): HasOne
    {
        return $this->hasOne(VendorRiskDecision::class)->latestOfMany('decided_at');
    }

    public function riskReviews(): HasMany
    {
        return $this->hasMany(VendorRiskReview::class);
    }

    public function latestRiskReview(): HasOne
    {
        return $this->hasOne(VendorRiskReview::class)->latestOfMany('reviewed_at');
    }

    public function riskIssues(): HasMany
    {
        return $this->hasMany(VendorRiskIssue::class);
    }

    public function openRiskIssues(): HasMany
    {
        return $this->hasMany(VendorRiskIssue::class)->where('status', 'open');
    }

    public function scopeWithThirdPartyRiskGraph(Builder $query): Builder
    {
        return $query->with(['latestRiskAssessment', 'latestRiskDecision', 'latestRiskReview', 'risks', 'openRiskIssues:id,vendor_id,severity']);
    }

    public function thirdPartyRiskSnapshot(?VendorRiskAssessment $assessment = null): array
    {
        $assessment ??= $this->relationLoaded('latestRiskAssessment') ? $this->latestRiskAssessment : $this->latestRiskAssessment()->firstOrFail();
        $risks = $this->relationLoaded('risks') ? $this->risks->sortBy('id')->values() : $this->risks()->orderBy('risks.id')->get();
        $riskIds = $risks->pluck('id')->all();
        $payload = [
            'assessment_id' => $assessment->id, 'risk_ids' => $riskIds,
            'risks' => $risks->map(fn (Risk $risk): array => $risk->only(['id', 'code', 'name', 'description', 'domain', 'status', 'inherent_likelihood', 'inherent_impact', 'inherent_risk', 'residual_likelihood', 'residual_impact', 'residual_risk', 'is_active']))->all(),
            'vendor' => $this->only(['id', 'vendor_manager_id', 'status', 'risk_rating', 'name']),
        ];

        return $payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))];
    }

    public function getThirdPartyRiskStatusAttribute(): string
    {
        $assessment = $this->relationLoaded('latestRiskAssessment') ? $this->latestRiskAssessment : $this->latestRiskAssessment()->first();
        if (! $assessment) {
            return 'assessment_required';
        }
        $risks = $this->relationLoaded('risks') ? $this->risks : $this->risks()->get();
        if ($risks->isEmpty()) {
            return 'risk_link_required';
        }
        $decision = $this->relationLoaded('latestRiskDecision') ? $this->latestRiskDecision : $this->latestRiskDecision()->first();
        if (! $decision) {
            return 'decision_required';
        }
        if ($decision->decision === ThirdPartyRiskDecisionType::Rejected) {
            return 'rejected';
        }
        if ($decision->decision === ThirdPartyRiskDecisionType::Terminated) {
            return 'terminated';
        }
        if ($decision->governance_fingerprint !== $this->thirdPartyRiskSnapshot($assessment)['fingerprint']) {
            return 'reapproval_required';
        }
        if ($decision->expires_at?->copy()->endOfDay()->isPast()) {
            return 'approval_expired';
        }
        $issues = $this->relationLoaded('openRiskIssues') ? $this->openRiskIssues : $this->openRiskIssues()->get();
        if ($issues->isNotEmpty()) {
            return $issues->contains('severity', 'critical') ? 'termination_required' : 'action_required';
        }
        $review = $this->relationLoaded('latestRiskReview') ? $this->latestRiskReview : $this->latestRiskReview()->first();
        $nextReview = $review?->next_review_at ?? $decision->next_review_at;
        if ($nextReview?->copy()->endOfDay()->isPast()) {
            return 'review_overdue';
        }

        return $decision->decision->value;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'status', 'risk_rating', 'vendor_manager_id', 'contact_name', 'contact_email'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
