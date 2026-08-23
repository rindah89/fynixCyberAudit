<?php

namespace App\Mcp\Tools;

use App\Enums\RiskDomain;
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
        $validated = $request->validate(['risk_id' => 'required|integer|min:1']);
        $user = $request->user();
        if (! $user) {
            return Response::text(json_encode(['success' => false, 'error' => 'Authentication required.'], JSON_PRETTY_PRINT));
        }

        $risk = Risk::query()->withPortfolioGovernanceGraph()->with([
            'governanceReviews.reviewer', 'governanceReviews.issue',
            'hierarchyChanges.changedBy:id,name', 'hierarchyChanges.previousParent:id,code,name', 'hierarchyChanges.parent:id,code,name',
        ])->find($validated['risk_id']);
        if (! $risk) {
            return Response::text(json_encode(['success' => false, 'error' => 'Risk not found.'], JSON_PRETTY_PRINT));
        }
        $ownerId = $risk->governanceProfile?->owner_id;
        $canReadPortfolio = $user->can('Manage Risk Portfolio') || $user->can('Read Risks');
        if (! $canReadPortfolio && $ownerId !== $user->id) {
            return Response::text(json_encode(['success' => false, 'error' => 'You do not have permission to inspect this risk portfolio record.'], JSON_PRETTY_PRINT));
        }
        $canReadParent = $canReadPortfolio || $risk->parentRisk?->governanceProfile?->owner_id === $user->id;

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
        return ['risk_id' => $schema->integer()->required()];
    }
}
