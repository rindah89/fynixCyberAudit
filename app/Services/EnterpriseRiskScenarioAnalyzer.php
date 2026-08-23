<?php

namespace App\Services;

use App\Enums\EnterpriseScenarioProbability;
use App\Enums\RiskDomain;
use App\Models\EnterpriseRiskScenario;
use App\Models\Risk;
use App\Models\RiskGovernanceProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnterpriseRiskScenarioAnalyzer
{
    public function __construct(private readonly EnterpriseRiskHierarchy $hierarchy) {}

    public function analyze(Risk $root, User $actor, array $data): EnterpriseRiskScenario
    {
        return DB::transaction(function () use ($root, $actor, $data): EnterpriseRiskScenario {
            DB::table('risk_hierarchy_mutexes')->where('id', 1)->lockForUpdate()->first();
            $root = Risk::query()->lockForUpdate()->findOrFail($root->id);
            if ($root->domain !== RiskDomain::Enterprise || ! $root->is_active || ! $root->governanceProfile()->exists()) {
                throw ValidationException::withMessages(['risk' => 'Scenario analysis requires an active governed enterprise risk.']);
            }

            $ids = [$root->id, ...$this->hierarchy->descendantIds($root->id)];
            $risks = Risk::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->where('is_active', true)->values();
            $profiles = RiskGovernanceProfile::query()->whereIn('risk_id', $risks->pluck('id'))->orderBy('id')->lockForUpdate()->get()->keyBy('risk_id');
            if ($profiles->count() !== $risks->count()) {
                throw ValidationException::withMessages(['hierarchy' => 'Every active scenario risk must have a governance profile.']);
            }

            $adjustments = collect($data['adjustments'])->keyBy(fn (array $adjustment): int => (int) $adjustment['risk_id']);
            if ($adjustments->keys()->diff($risks->pluck('id'))->isNotEmpty()) {
                throw ValidationException::withMessages(['adjustments' => 'Adjustments may reference only active risks in this enterprise hierarchy.']);
            }
            if (! $adjustments->contains(fn (array $adjustment): bool => (int) $adjustment['likelihood_shift'] !== 0 || (int) $adjustment['impact_shift'] !== 0)) {
                throw ValidationException::withMessages(['adjustments' => 'At least one likelihood or impact adjustment must be non-zero.']);
            }

            $items = $this->buildItems($risks, $profiles, $adjustments);
            $scores = $items->pluck('stressed_score');
            $snapshot = $items->all();
            $scenario = EnterpriseRiskScenario::query()->create([
                'root_risk_id' => $root->id,
                'version' => ((int) EnterpriseRiskScenario::query()->where('root_risk_id', $root->id)->max('version')) + 1,
                'name' => $data['name'], 'narrative' => $data['narrative'],
                'horizon_months' => $data['horizon_months'],
                'probability_band' => EnterpriseScenarioProbability::from($data['probability_band']),
                'created_by' => $actor->id, 'risk_count' => $items->count(),
                'baseline_score_sum' => $items->sum('baseline_score'), 'stressed_score_sum' => $scores->sum(),
                'score_delta' => $scores->sum() - $items->sum('baseline_score'),
                'stressed_score_maximum' => $scores->max() ?? 0,
                'above_appetite_count' => $items->filter(fn (array $item): bool => $item['stressed_score'] > $item['appetite_threshold_snapshot'])->count(),
                'stressed_band_counts' => $this->bandCounts($scores),
                'hierarchy_snapshot' => $snapshot,
                'hierarchy_fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'analyzed_at' => now(),
            ]);
            $scenario->items()->createMany($items->all());

            return $scenario->load(['creator:id,name', 'items']);
        }, 3);
    }

    private function buildItems(Collection $risks, Collection $profiles, Collection $adjustments): Collection
    {
        return $risks->map(function (Risk $risk) use ($profiles, $adjustments): array {
            $adjustment = $adjustments->get($risk->id, ['likelihood_shift' => 0, 'impact_shift' => 0]);
            $likelihoodShift = (int) $adjustment['likelihood_shift'];
            $impactShift = (int) $adjustment['impact_shift'];
            $stressedLikelihood = max(1, min(5, $risk->residual_likelihood + $likelihoodShift));
            $stressedImpact = max(1, min(5, $risk->residual_impact + $impactShift));
            $profile = $profiles->get($risk->id);

            return [
                'risk_id' => $risk->id, 'risk_code_snapshot' => $risk->code, 'risk_name_snapshot' => $risk->name,
                'parent_risk_id_snapshot' => $risk->parent_risk_id, 'owner_id_snapshot' => $profile->owner_id,
                'appetite_threshold_snapshot' => $profile->appetite_threshold,
                'baseline_likelihood' => $risk->residual_likelihood, 'baseline_impact' => $risk->residual_impact,
                'baseline_score' => $risk->residual_risk, 'likelihood_shift' => $likelihoodShift, 'impact_shift' => $impactShift,
                'stressed_likelihood' => $stressedLikelihood, 'stressed_impact' => $stressedImpact,
                'stressed_score' => $stressedLikelihood * $stressedImpact, 'rationale' => $adjustment['rationale'] ?? null,
            ];
        });
    }

    private function bandCounts(Collection $scores): array
    {
        return [
            'critical' => $scores->filter(fn (int $score): bool => $score >= 20)->count(),
            'high' => $scores->filter(fn (int $score): bool => $score >= 12 && $score < 20)->count(),
            'medium' => $scores->filter(fn (int $score): bool => $score >= 6 && $score < 12)->count(),
            'low' => $scores->filter(fn (int $score): bool => $score < 6)->count(),
        ];
    }
}
