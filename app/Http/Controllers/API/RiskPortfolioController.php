<?php

namespace App\Http\Controllers\API;

use App\Enums\RiskGovernanceDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShowEnterpriseRiskRollupRequest;
use App\Http\Requests\StoreEnterpriseRiskParentRequest;
use App\Http\Requests\StoreRiskGovernanceProfileRequest;
use App\Http\Requests\StoreRiskGovernanceReviewRequest;
use App\Models\Risk;
use App\Services\EnterpriseRiskHierarchy;
use App\Services\RiskPortfolioManager;
use Illuminate\Http\JsonResponse;

class RiskPortfolioController extends Controller
{
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
