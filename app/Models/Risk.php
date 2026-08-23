<?php

namespace App\Models;

use Aliziodev\LaravelTaxonomy\Traits\HasTaxonomy;
use App\Enums\MitigationType;
use App\Enums\RiskDomain;
use App\Enums\RiskStatus;
use App\Mcp\Traits\HasMcpSupport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Risk extends Model
{
    use HasFactory, HasMcpSupport, HasTaxonomy, LogsActivity;

    public static function mcpConfig(): array
    {
        $defaults = static::buildDefaultMcpConfig(new static);
        unset($defaults['create_fields']['parent_risk_id'], $defaults['update_fields']['parent_risk_id'], $defaults['field_descriptions']['parent_risk_id']);

        return [
            'create_fields' => $defaults['create_fields'],
            'update_fields' => $defaults['update_fields'],
            'field_descriptions' => $defaults['field_descriptions'],
            'list_relations' => array_values(array_diff($defaults['list_relations'], ['parentRisk'])),
            'list_counts' => array_values(array_diff($defaults['list_counts'], ['childRisks', 'hierarchyChanges', 'enterpriseScenarios', 'enterpriseScenarioItems'])),
            'detail_relations' => array_values(array_diff($defaults['detail_relations'], ['parentRisk', 'childRisks', 'hierarchyChanges', 'enterpriseScenarios', 'latestEnterpriseScenario', 'enterpriseScenarioItems'])),
        ];
    }

    protected $casts = [
        'id' => 'integer',
        'action' => MitigationType::class,
        'domain' => RiskDomain::class,
        'status' => RiskStatus::class,
        'is_active' => 'boolean',
    ];

    protected $fillable = [
        'code',
        'name',
        'description',
        'domain',
        'status',
        'inherent_likelihood',
        'inherent_impact',
        'inherent_risk',
        'residual_likelihood',
        'residual_impact',
        'residual_risk',
        'is_active',
        'parent_risk_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (Risk $risk): void {
            $risk->inherent_likelihood ??= 3;
            $risk->inherent_impact ??= 3;
            $risk->residual_likelihood ??= 3;
            $risk->residual_impact ??= 3;
            $risk->inherent_risk = $risk->inherent_likelihood * $risk->inherent_impact;
            $risk->residual_risk = $risk->residual_likelihood * $risk->residual_impact;
        });
        static::updating(function (Risk $risk): void {
            if ($risk->isDirty('domain') && $risk->governanceProfile()->exists()) {
                throw new LogicException('A risk domain cannot be changed after portfolio governance begins. Create a new risk record instead.');
            }
        });
        static::deleting(function (Risk $risk): void {
            if ($risk->hasEnterprisePortfolioEvidence()) {
                throw new LogicException('Risks with enterprise portfolio hierarchy or scenario evidence cannot be deleted.');
            }
        });
    }

    public function implementations(): BelongsToMany
    {
        return $this->BelongsToMany(Implementation::class);
    }

    public function policies(): BelongsToMany
    {
        return $this->belongsToMany(Policy::class, 'policy_risk')
            ->withTimestamps();
    }

    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class);
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class)
            ->withTimestamps();
    }

    public function mitigations(): MorphMany
    {
        return $this->morphMany(Mitigation::class, 'mitigatable');
    }

    public function governanceProfile(): HasOne
    {
        return $this->hasOne(RiskGovernanceProfile::class);
    }

    public function parentRisk(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_risk_id');
    }

    public function childRisks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_risk_id');
    }

    public function hierarchyChanges(): HasMany
    {
        return $this->hasMany(RiskHierarchyChange::class);
    }

    public function enterpriseScenarios(): HasMany
    {
        return $this->hasMany(EnterpriseRiskScenario::class, 'root_risk_id');
    }

    public function latestEnterpriseScenario(): HasOne
    {
        return $this->hasOne(EnterpriseRiskScenario::class, 'root_risk_id')->latestOfMany('version');
    }

    public function enterpriseScenarioItems(): HasMany
    {
        return $this->hasMany(EnterpriseRiskScenarioItem::class);
    }

    public function hasEnterprisePortfolioEvidence(): bool
    {
        return $this->parent_risk_id !== null
            || $this->childRisks()->exists()
            || $this->hierarchyChanges()->exists()
            || $this->enterpriseScenarios()->exists()
            || $this->enterpriseScenarioItems()->exists()
            || RiskHierarchyChange::query()->where('previous_parent_risk_id', $this->id)->orWhere('parent_risk_id', $this->id)->exists();
    }

    public function governanceReviews(): HasMany
    {
        return $this->hasMany(RiskGovernanceReview::class);
    }

    public function latestGovernanceReview(): HasOne
    {
        return $this->hasOne(RiskGovernanceReview::class)->latestOfMany('reviewed_at');
    }

    public function governanceIssues(): HasMany
    {
        return $this->hasMany(RiskGovernanceIssue::class);
    }

    public function openGovernanceIssues(): HasMany
    {
        return $this->hasMany(RiskGovernanceIssue::class)->where('status', 'open');
    }

    public function scopeWithPortfolioGovernanceGraph(Builder $query): Builder
    {
        return $query->with([
            'governanceProfile.owner', 'governanceProfile.businessService', 'latestGovernanceReview',
            'assets', 'implementations.controls', 'openGovernanceIssues:id,risk_id,severity',
            'parentRisk:id,code,name', 'parentRisk.governanceProfile:id,risk_id,owner_id', 'childRisks:id,parent_risk_id',
            'latestEnterpriseScenario',
        ])->withCount(['childRisks', 'enterpriseScenarios']);
    }

    public function portfolioGovernanceSnapshot(?RiskGovernanceProfile $profile = null): array
    {
        $profile ??= $this->relationLoaded('governanceProfile') ? $this->governanceProfile : $this->governanceProfile()->firstOrFail();
        $assets = $this->relationLoaded('assets') ? $this->assets->sortBy('id')->values() : $this->assets()->orderBy('assets.id')->get();
        $implementationsReady = $this->relationLoaded('implementations') && $this->implementations->every(fn (Implementation $implementation): bool => $implementation->relationLoaded('controls'));
        $implementations = $implementationsReady ? $this->implementations->sortBy('id')->values() : $this->implementations()->with('controls')->orderBy('implementations.id')->get();
        $service = $profile->relationLoaded('businessService') ? $profile->businessService : $profile->businessService()->first();
        $payload = [
            'risk' => $this->only(['id', 'code', 'name', 'description', 'domain', 'status', 'inherent_likelihood', 'inherent_impact', 'inherent_risk', 'residual_likelihood', 'residual_impact', 'residual_risk', 'is_active']),
            'profile' => $profile->only(['id', 'owner_id', 'appetite_threshold', 'review_frequency', 'strategic_objective', 'business_service_id', 'context_notes']),
            'business_service' => $service?->only(['id', 'owner_id', 'code', 'name', 'criticality', 'status']),
            'assets' => $assets->map(fn (Asset $asset): array => $asset->only(['id', 'asset_tag', 'name', 'is_active', 'asset_criticality_id', 'data_classification_id', 'asset_exposure_id']))->all(),
            'implementations' => $implementations->map(fn (Implementation $implementation): array => $implementation->only(['id', 'code', 'title', 'details', 'status', 'effectiveness']) + [
                'controls' => $implementation->controls->sortBy('id')->values()->map(fn (Control $control): array => $control->only(['id', 'code', 'title', 'status', 'effectiveness', 'applicability']))->all(),
            ])->all(),
        ];

        return $payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))];
    }

    public function getPortfolioGovernanceStatusAttribute(): string
    {
        if (! in_array($this->domain, [RiskDomain::Enterprise, RiskDomain::Operational, RiskDomain::Technology], true)) {
            return 'not_applicable';
        }
        $profile = $this->relationLoaded('governanceProfile') ? $this->governanceProfile : $this->governanceProfile()->with('businessService')->first();
        if (! $profile) {
            return 'profile_required';
        }
        $review = $this->relationLoaded('latestGovernanceReview') ? $this->latestGovernanceReview : $this->latestGovernanceReview()->first();
        if (! $review) {
            return 'review_required';
        }
        $issues = $this->relationLoaded('openGovernanceIssues') ? $this->openGovernanceIssues : $this->openGovernanceIssues()->get();
        if ($issues->isNotEmpty()) {
            return 'action_required';
        }
        if ($review->governance_fingerprint !== $this->portfolioGovernanceSnapshot($profile)['fingerprint']) {
            return 're_review_required';
        }
        if ($review->next_review_at->copy()->endOfDay()->isPast()) {
            return 'review_overdue';
        }

        return $review->decision->value;
    }

    public function latestMitigation(): ?Mitigation
    {
        return $this->mitigations()->latest('date_implemented')->first();
    }

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return 'risks_index';
    }

    /**
     * Get the array representation of the model for search.
     */
    public function toSearchableArray(): array
    {
        return $this->toArray();
    }

    public static function next()
    {
        return static::max('id') + 1;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'code',
                'name',
                'description',
                'domain',
                'parent_risk_id',
                'inherent_likelihood',
                'inherent_impact',
                'inherent_risk',
                'residual_likelihood',
                'residual_impact',
                'residual_risk',
                'status',
                'is_active',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
