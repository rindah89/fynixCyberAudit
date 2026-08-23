<?php

namespace App\Http\Controllers\API;

use App\Enums\RiskGovernanceDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRiskGovernanceProfileRequest;
use App\Http\Requests\StoreRiskGovernanceReviewRequest;
use App\Models\Risk;
use App\Services\RiskPortfolioManager;
use Illuminate\Http\JsonResponse;

class RiskPortfolioController extends Controller
{
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
