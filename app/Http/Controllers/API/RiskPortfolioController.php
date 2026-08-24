<?php

namespace App\Http\Controllers\API;

use App\Enums\RiskGovernanceDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListEnterpriseRiskScenariosRequest;
use App\Http\Requests\ListOperationalLossEventsRequest;
use App\Http\Requests\ListRiskIndicatorObservationsRequest;
use App\Http\Requests\ListRiskIndicatorsRequest;
use App\Http\Requests\ListTechnologyExposureAssessmentsRequest;
use App\Http\Requests\ShowEnterpriseRiskRollupRequest;
use App\Http\Requests\ShowEnterpriseRiskScenarioRequest;
use App\Http\Requests\StoreEnterpriseRiskParentRequest;
use App\Http\Requests\StoreEnterpriseRiskScenarioRequest;
use App\Http\Requests\StoreOperationalLossEventRequest;
use App\Http\Requests\StoreRiskGovernanceProfileRequest;
use App\Http\Requests\StoreRiskGovernanceReviewRequest;
use App\Http\Requests\StoreRiskIndicatorObservationRequest;
use App\Http\Requests\StoreRiskIndicatorRequest;
use App\Http\Requests\StoreTechnologyExposureAssessmentRequest;
use App\Http\Requests\UpdateRiskIndicatorRequest;
use App\Models\EnterpriseRiskScenario;
use App\Models\Risk;
use App\Models\RiskIndicator;
use App\Services\EnterpriseRiskHierarchy;
use App\Services\EnterpriseRiskScenarioAnalyzer;
use App\Services\OperationalLossEventManager;
use App\Services\RiskIndicatorManager;
use App\Services\RiskPortfolioManager;
use App\Services\TechnologyExposureAssessmentManager;
use Illuminate\Http\JsonResponse;

class RiskPortfolioController extends Controller
{
    public function assessTechnologyExposure(StoreTechnologyExposureAssessmentRequest $request, Risk $risk, TechnologyExposureAssessmentManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->assess($risk, $request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function technologyExposureAssessments(ListTechnologyExposureAssessmentsRequest $request, Risk $risk): JsonResponse
    {
        return response()->json($risk->technologyExposureAssessments()->with(['asset:id,asset_tag,name', 'assessor:id,name'])->latest('version')->paginate($request->integer('per_page', 50)));
    }

    public function storeIndicator(StoreRiskIndicatorRequest $request, Risk $risk, RiskIndicatorManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->define($risk, $request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function indicators(ListRiskIndicatorsRequest $request, Risk $risk): JsonResponse
    {
        return response()->json($risk->riskIndicators()->with(['owner:id,name', 'latestObservation.observer:id,name'])->withCount('observations')->latest('id')->paginate($request->integer('per_page', 50)));
    }

    public function observeIndicator(StoreRiskIndicatorObservationRequest $request, RiskIndicator $indicator, RiskIndicatorManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->observe($indicator, $request->user(), $request->validated()), 'indicator' => $indicator->refresh()], JsonResponse::HTTP_CREATED);
    }

    public function indicatorObservations(ListRiskIndicatorObservationsRequest $request, RiskIndicator $indicator): JsonResponse
    {
        return response()->json($indicator->observations()->with('observer:id,name')->latest('observed_at')->latest('id')->paginate($request->integer('per_page', 50)));
    }

    public function updateIndicator(UpdateRiskIndicatorRequest $request, RiskIndicator $indicator, RiskIndicatorManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->update($indicator, $request->user(), $request->validated())]);
    }

    public function recordLossEvent(StoreOperationalLossEventRequest $request, Risk $risk, OperationalLossEventManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->record($risk, $request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function lossEvents(ListOperationalLossEventsRequest $request, Risk $risk): JsonResponse
    {
        return response()->json($risk->operationalLossEvents()
            ->with(['reporter:id,name', 'businessService:id,code,name'])
            ->latest('occurred_at')->latest('id')
            ->paginate($request->integer('per_page', 50)));
    }

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
