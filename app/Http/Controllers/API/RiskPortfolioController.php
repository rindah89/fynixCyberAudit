<?php

namespace App\Http\Controllers\API;

use App\Enums\RiskGovernanceDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListEnterpriseRiskScenariosRequest;
use App\Http\Requests\ShowEnterpriseRiskRollupRequest;
use App\Http\Requests\ShowEnterpriseRiskScenarioRequest;
use App\Http\Requests\StoreEnterpriseRiskParentRequest;
use App\Http\Requests\StoreEnterpriseRiskScenarioRequest;
use App\Http\Requests\StoreRiskGovernanceProfileRequest;
use App\Http\Requests\StoreRiskGovernanceReviewRequest;
use App\Models\EnterpriseRiskScenario;
use App\Models\Risk;
use App\Services\EnterpriseRiskHierarchy;
use App\Services\EnterpriseRiskScenarioAnalyzer;
use App\Services\RiskPortfolioManager;
use Illuminate\Http\JsonResponse;

class RiskPortfolioController extends Controller
{
    public function scenario(StoreEnterpriseRiskScenarioRequest $request, Risk $risk, EnterpriseRiskScenarioAnalyzer $analyzer): JsonResponse
    {
        return response()->json(['data' => $analyzer->analyze($risk, $request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function scenarios(ListEnterpriseRiskScenariosRequest $request, Risk $risk): JsonResponse
    {
        $scenarios = $risk->enterpriseScenarios()->with('creator:id,name')->latest('version')->paginate($request->integer('per_page', 50));
        if (! $request->user()->can('Manage Risk Portfolio') && ! $request->user()->can('Read Risks')) {
            $scenarios->getCollection()->each->makeHidden(['created_by', 'creator']);
        }

        return response()->json($scenarios);
    }

    public function showScenario(ShowEnterpriseRiskScenarioRequest $request, EnterpriseRiskScenario $scenario): JsonResponse
    {
        $canReadItems = $request->user()->can('Manage Risk Portfolio') || $request->user()->can('Read Risks');
        $scenario->load('creator:id,name');
        if ($canReadItems) {
            $items = $scenario->items()->orderBy('id')->paginate($request->integer('item_per_page', 100), ['*'], 'item_page');
            $scenario->setRelation('items', $items->getCollection());
        } else {
            $scenario->setRelation('items', collect())->makeHidden(['created_by', 'creator']);
            $items = null;
        }

        return response()->json([
            'data' => $scenario,
            'items_restricted' => ! $canReadItems,
            'items_pagination' => $items ? [
                'current_page' => $items->currentPage(), 'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(), 'total' => $items->total(),
            ] : null,
        ]);
    }

    public function parent(StoreEnterpriseRiskParentRequest $request, Risk $risk, EnterpriseRiskHierarchy $hierarchy): JsonResponse
    {
        $record = $hierarchy->assignParent($risk, $request->validated('parent_risk_id'), $request->user());

        return response()->json(['data' => ['risk' => $record, 'parent' => $record->parentRisk]]);
    }

    public function rollup(ShowEnterpriseRiskRollupRequest $request, Risk $risk, EnterpriseRiskHierarchy $hierarchy): JsonResponse
    {
        return response()->json(['data' => $hierarchy->rollup($risk)]);
    }

    public function profile(StoreRiskGovernanceProfileRequest $request, Risk $risk, RiskPortfolioManager $manager): JsonResponse
    {
        $profile = $manager->profile($risk, $request->validated());

        return response()->json(['data' => $profile->load(['owner', 'businessService']), 'risk' => $risk->refresh()->append('portfolio_governance_status')]);
    }

    public function review(StoreRiskGovernanceReviewRequest $request, Risk $risk, RiskPortfolioManager $manager): JsonResponse
    {
        $data = $request->validated();
        $review = $manager->review($risk, $request->user(), RiskGovernanceDecision::from($data['decision']), $data);

        return response()->json(['data' => $review, 'risk' => $risk->refresh()->append('portfolio_governance_status')], JsonResponse::HTTP_CREATED);
    }
}
