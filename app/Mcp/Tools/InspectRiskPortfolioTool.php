<?php

namespace App\Mcp\Tools;

use App\Enums\RiskDomain;
use App\Models\EnterpriseRiskScenario;
use App\Models\Risk;
use App\Services\EnterpriseRiskHierarchy;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class InspectRiskPortfolioTool extends Tool
{
    protected string $name = 'Risk';

    protected string $description = 'Governance.';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'risk_id' => 'required|integer|min:1',
            'scenario' => ['nullable', 'string', 'regex:/^[1-9][0-9]*(?::[1-9][0-9]*)?$/'],
        ]);
        $user = $request->user();
        if (! $user) {
            return Response::text(json_encode(['success' => false, 'error' => 'Authentication required.'], JSON_PRETTY_PRINT));
        }

        $risk = Risk::query()->withPortfolioGovernanceGraph()->with([
            'governanceReviews.reviewer', 'governanceReviews.issue',
            'hierarchyChanges.changedBy:id,name', 'hierarchyChanges.previousParent:id,code,name', 'hierarchyChanges.parent:id,code,name',
            'enterpriseScenarios' => fn ($query) => $query->with('creator:id,name')->latest('version')->limit(10),
        ])->find($validated['risk_id']);
        if (! $risk) {
            return Response::text(json_encode(['success' => false, 'error' => 'Risk not found.'], JSON_PRETTY_PRINT));
        }
        $ownerId = $risk->governanceProfile?->owner_id;
        $canReadPortfolio = $user->can('Manage Risk Portfolio') || $user->can('Read Risks');
        if (! $canReadPortfolio && $ownerId !== $user->id) {
            return Response::text(json_encode(['success' => false, 'error' => 'You do not have permission to inspect this risk portfolio record.'], JSON_PRETTY_PRINT));
        }
        $selectedItems = null;
        $selectedScenario = null;
        if (isset($validated['scenario'])) {
            [$scenarioId, $page] = array_pad(explode(':', $validated['scenario'], 2), 2, 1);
            $selectedScenario = $risk->enterpriseScenarios()->with('creator:id,name')->whereKey((int) $scenarioId)->first();
            if (! $selectedScenario) {
                return Response::text(json_encode(['success' => false, 'error' => 'Scenario not found for this risk.'], JSON_PRETTY_PRINT));
            }
            if ($canReadPortfolio) {
                $items = $selectedScenario->items()->orderBy('id')->paginate(5, ['*'], 'page', (int) $page);
                $selectedItems = [
                    'items' => $items->getCollection(),
                    'pagination' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => $items->perPage(), 'total' => $items->total()],
                ];
            }
        }
        $canReadParent = $canReadPortfolio || $risk->parentRisk?->governanceProfile?->owner_id === $user->id;
        $scenarioOutput = $this->boundedScenarioOutput($risk, $selectedScenario, $selectedItems, $canReadPortfolio);

        return Response::text(json_encode([
            'success' => true,
            'risk' => [
                'id' => $risk->id, 'code' => $risk->code, 'name' => $risk->name, 'domain' => $risk->domain?->value,
                'status' => $risk->status?->value, 'inherent_score' => $risk->inherent_risk, 'residual_score' => $risk->residual_risk,
                'governance_status' => $risk->portfolio_governance_status,
                'profile' => $risk->governanceProfile?->only(['id', 'owner_id', 'appetite_threshold', 'review_frequency', 'strategic_objective', 'business_service_id', 'context_notes', 'next_review_at']),
                'asset_ids' => $risk->assets->pluck('id')->all(), 'implementation_ids' => $risk->implementations->pluck('id')->all(),
                'has_parent' => $risk->parent_risk_id !== null,
                'parent_risk_id' => $canReadParent ? $risk->parent_risk_id : null,
                'child_risk_ids' => $canReadPortfolio ? $risk->childRisks->pluck('id')->all() : $risk->childRisks()->whereHas('governanceProfile', fn ($query) => $query->where('owner_id', $user->id))->pluck('risks.id')->all(),
                'hierarchy_changes' => $canReadPortfolio ? $risk->hierarchyChanges->sortByDesc('changed_at')->values()->map(fn ($change): array => [
                    'previous_parent_risk_id' => $change->previous_parent_risk_id, 'parent_risk_id' => $change->parent_risk_id,
                    'changed_by' => $change->changed_by, 'changed_by_name' => $change->changedBy?->name,
                    'changed_at' => $change->changed_at?->toIso8601String(),
                ])->all() : [],
                'enterprise_rollup' => $risk->domain === RiskDomain::Enterprise && $risk->governanceProfile
                    ? app(EnterpriseRiskHierarchy::class)->boundedRollup($risk) : null,
                'enterprise_scenarios' => $scenarioOutput['enterprise_scenarios'],
                'enterprise_scenario_detail' => $scenarioOutput['enterprise_scenario_detail'],
                'enterprise_scenario_output_truncated' => $scenarioOutput['enterprise_scenario_output_truncated'],
                'reviews' => $risk->governanceReviews->sortByDesc('reviewed_at')->values()->map(fn ($review): array => [
                    'id' => $review->id, 'reviewed_by' => $review->reviewed_by, 'reviewer' => $review->reviewer?->name,
                    'decision' => $review->decision->value, 'domain' => $review->domain_snapshot->value,
                    'inherent_score' => $review->inherent_score_snapshot, 'residual_score' => $review->residual_score_snapshot,
                    'appetite_threshold' => $review->appetite_threshold_snapshot, 'summary' => $review->summary,
                    'governance_snapshot' => $review->governance_snapshot,
                    'evidence_reference' => $review->evidence_reference, 'next_review_at' => $review->next_review_at?->toDateString(),
                    'reviewed_at' => $review->reviewed_at?->toIso8601String(), 'issue_status' => $review->issue?->status,
                ])->all(),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'risk_id' => $schema->integer()->required(),
            'scenario' => $schema->string(),
        ];
    }

    private function scenarioSummary(EnterpriseRiskScenario $scenario): array
    {
        return [
            'id' => $scenario->id, 'version' => $scenario->version, 'name' => $this->byteExcerpt($scenario->name, 40),
            'horizon_months' => $scenario->horizon_months,
            'probability_band' => $scenario->probability_band->value, 'risk_count' => $scenario->risk_count,
            'baseline_score_sum' => $scenario->baseline_score_sum, 'stressed_score_sum' => $scenario->stressed_score_sum,
            'score_delta' => $scenario->score_delta, 'stressed_score_maximum' => $scenario->stressed_score_maximum,
            'above_appetite_count' => $scenario->above_appetite_count, 'stressed_band_counts' => $scenario->stressed_band_counts,
            'analyzed_at' => $scenario->analyzed_at?->toIso8601String(),
        ];
    }

    private function scenarioDetail(EnterpriseRiskScenario $scenario, ?array $selectedItems, bool $canReadPortfolio): array
    {
        $detail = $this->scenarioSummary($scenario);
        $detail['narrative_excerpt'] = $this->byteExcerpt($scenario->narrative, 120);
        if ($canReadPortfolio && $selectedItems) {
            $detail['created_by'] = $scenario->created_by;
            $detail['creator'] = $this->byteExcerpt((string) $scenario->creator?->name, 40);
            $detail['items'] = $selectedItems['items']->map(fn ($item): array => $item->only([
                'risk_id', 'parent_risk_id_snapshot', 'owner_id_snapshot',
                'appetite_threshold_snapshot', 'baseline_likelihood', 'baseline_impact', 'baseline_score',
                'likelihood_shift', 'impact_shift', 'stressed_likelihood', 'stressed_impact', 'stressed_score',
            ]) + [
                'risk_code_snapshot' => $this->byteExcerpt($item->risk_code_snapshot, 24),
                'risk_name_snapshot' => $this->byteExcerpt($item->risk_name_snapshot, 48),
                'rationale_excerpt' => $this->byteExcerpt((string) $item->rationale, 48),
            ])->all();
            $detail['items_pagination'] = $selectedItems['pagination'];
        }

        return $detail;
    }

    private function boundedScenarioOutput(Risk $risk, ?EnterpriseRiskScenario $selectedScenario, ?array $selectedItems, bool $canReadPortfolio): array
    {
        $output = [
            'enterprise_scenarios' => $risk->enterpriseScenarios->sortByDesc('version')->values()->map(fn ($scenario): array => $this->scenarioSummary($scenario))->all(),
            'enterprise_scenario_detail' => $selectedScenario ? $this->scenarioDetail($selectedScenario, $selectedItems, $canReadPortfolio) : null,
            'enterprise_scenario_output_truncated' => false,
        ];
        while (strlen(json_encode($output, JSON_THROW_ON_ERROR)) > 6000 && count($output['enterprise_scenarios']) > 0) {
            array_pop($output['enterprise_scenarios']);
            $output['enterprise_scenario_output_truncated'] = true;
        }
        while (strlen(json_encode($output, JSON_THROW_ON_ERROR)) > 6000 && count($output['enterprise_scenario_detail']['items'] ?? []) > 1) {
            array_pop($output['enterprise_scenario_detail']['items']);
            $output['enterprise_scenario_output_truncated'] = true;
        }

        return $output;
    }

    private function byteExcerpt(string $value, int $bytes): string
    {
        return mb_strcut($value, 0, $bytes, 'UTF-8');
    }
}
