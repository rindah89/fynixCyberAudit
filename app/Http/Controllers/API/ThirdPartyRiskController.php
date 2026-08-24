<?php

namespace App\Http\Controllers\API;

use App\Enums\ThirdPartyRiskDecisionType;
use App\Enums\ThirdPartyRiskReviewOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteThirdPartyOffboardingRequirementRequest;
use App\Http\Requests\CompleteThirdPartyOnboardingRequirementRequest;
use App\Http\Requests\DecideThirdPartyCollaborationRequest;
use App\Http\Requests\ListFourthPartyConcentrationsRequest;
use App\Http\Requests\ListThirdPartyEngagementMonitoringRequest;
use App\Http\Requests\ListThirdPartyEngagementsRequest;
use App\Http\Requests\ListVendorFourthPartyDependenciesRequest;
use App\Http\Requests\MapVendorRiskRequest;
use App\Http\Requests\ShowThirdPartyEngagementRequest;
use App\Http\Requests\StoreFourthPartyDependencyRequest;
use App\Http\Requests\StoreThirdPartyCollaborationRequest;
use App\Http\Requests\StoreThirdPartyContractRiskReviewRequest;
use App\Http\Requests\StoreThirdPartyEngagementDueDiligenceReviewRequest;
use App\Http\Requests\StoreThirdPartyEngagementMonitoringIndicatorRequest;
use App\Http\Requests\StoreThirdPartyEngagementMonitoringObservationRequest;
use App\Http\Requests\StoreThirdPartyEngagementRequest;
use App\Http\Requests\StoreThirdPartyOffboardingReadinessReviewRequest;
use App\Http\Requests\StoreThirdPartyOffboardingRequirementRequest;
use App\Http\Requests\StoreThirdPartyOnboardingReadinessReviewRequest;
use App\Http\Requests\StoreThirdPartyOnboardingRequirementRequest;
use App\Http\Requests\StoreVendorRiskAssessmentRequest;
use App\Http\Requests\StoreVendorRiskDecisionRequest;
use App\Http\Requests\StoreVendorRiskReviewRequest;
use App\Http\Requests\TransitionThirdPartyEngagementRequest;
use App\Models\Risk;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\ThirdPartyEngagementMonitoringIndicator;
use App\Models\ThirdPartyEngagementOffboardingRequirement;
use App\Models\ThirdPartyEngagementOnboardingRequirement;
use App\Models\Vendor;
use App\Services\FourthPartyDependencyManager;
use App\ThirdPartyRisk\ThirdPartyContractRiskManager;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationManager;
use App\ThirdPartyRisk\ThirdPartyEngagementDueDiligenceManager;
use App\ThirdPartyRisk\ThirdPartyEngagementManager;
use App\ThirdPartyRisk\ThirdPartyEngagementMonitoringManager;
use App\ThirdPartyRisk\ThirdPartyEngagementOffboardingManager;
use App\ThirdPartyRisk\ThirdPartyEngagementOnboardingManager;
use App\ThirdPartyRisk\ThirdPartyRiskManager;
use Illuminate\Http\JsonResponse;

class ThirdPartyRiskController extends Controller
{
    public function engagements(ListThirdPartyEngagementsRequest $request, Vendor $vendor): JsonResponse
    {
        return response()->json($vendor->engagements()->with(['businessOwner:id,name', 'proposer:id,name', 'approver:id,name'])->withCount('events')->latest('id')->paginate($request->integer('per_page', 50)));
    }

    public function proposeEngagement(StoreThirdPartyEngagementRequest $request, Vendor $vendor, ThirdPartyEngagementManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->propose($request->user(), $vendor, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function showEngagement(ShowThirdPartyEngagementRequest $request, ThirdPartyEngagement $engagement): JsonResponse
    {
        return response()->json(['data' => $engagement->load(['businessOwner:id,name,email', 'proposer:id,name', 'approver:id,name', 'events.actor:id,name', 'contractRiskReviews.reviewer:id,name', 'offboardingRequirements.owner:id,name', 'offboardingRequirements.definer:id,name', 'offboardingRequirements.completions.completer:id,name', 'offboardingReadinessReviews.reviewer:id,name', 'monitoringIndicators.owner:id,name', 'monitoringIndicators.definer:id,name', 'monitoringIndicators.latestObservation.observer:id,name'])]);
    }

    public function engagementEvents(ShowThirdPartyEngagementRequest $request, ThirdPartyEngagement $engagement): JsonResponse
    {
        return response()->json($engagement->events()->with('actor:id,name')->paginate($request->integer('per_page', 50)));
    }

    public function transitionEngagement(TransitionThirdPartyEngagementRequest $request, ThirdPartyEngagement $engagement, ThirdPartyEngagementManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->transition($request->user(), $engagement, $request->validated())]);
    }

    public function reviewContractRisk(StoreThirdPartyContractRiskReviewRequest $request, ThirdPartyEngagement $engagement, ThirdPartyContractRiskManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->review($request->user(), $engagement, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function contractRiskReviews(ShowThirdPartyEngagementRequest $request, ThirdPartyEngagement $engagement): JsonResponse
    {
        return response()->json($engagement->contractRiskReviews()->with('reviewer:id,name')->latest('version')->paginate($request->integer('per_page', 50)));
    }

    public function reviewDueDiligence(StoreThirdPartyEngagementDueDiligenceReviewRequest $request, ThirdPartyEngagement $engagement, ThirdPartyEngagementDueDiligenceManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->review($request->user(), $engagement, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function dueDiligenceReviews(ShowThirdPartyEngagementRequest $request, ThirdPartyEngagement $engagement, ThirdPartyEngagementDueDiligenceManager $manager): JsonResponse
    {
        $page = $engagement->dueDiligenceReviews()->with('reviewer:id,name')->latest('version')->paginate($request->integer('per_page', 50));
        $page->setCollection($manager->visibleReviews($page->getCollection(), $request->user()));

        return response()->json($page);
    }

    public function monitoringIndicators(ListThirdPartyEngagementMonitoringRequest $request, ThirdPartyEngagement $engagement): JsonResponse
    {
        $page = $engagement->monitoringIndicators()->with(['owner:id,name', 'definer:id,name', 'latestObservation.observer:id,name'])->latest('id')->paginate($request->integer('per_page', 50));
        $page->through(fn (ThirdPartyEngagementMonitoringIndicator $indicator) => $indicator->append('monitoring_status'));

        return response()->json($page);
    }

    public function defineMonitoringIndicator(StoreThirdPartyEngagementMonitoringIndicatorRequest $request, ThirdPartyEngagement $engagement, ThirdPartyEngagementMonitoringManager $manager): JsonResponse
    {
        $indicator = $manager->define($request->user(), $engagement, $request->validated());

        return response()->json(['data' => $indicator->append('monitoring_status')], JsonResponse::HTTP_CREATED);
    }

    public function monitoringObservations(ListThirdPartyEngagementMonitoringRequest $request, ThirdPartyEngagementMonitoringIndicator $indicator): JsonResponse
    {
        return response()->json($indicator->observations()->with('observer:id,name')->latest('version')->paginate($request->integer('per_page', 50)));
    }

    public function observeMonitoringIndicator(StoreThirdPartyEngagementMonitoringObservationRequest $request, ThirdPartyEngagementMonitoringIndicator $indicator, ThirdPartyEngagementMonitoringManager $manager): JsonResponse
    {
        $observation = $manager->observe($request->user(), $indicator, $request->validated());

        return response()->json(['data' => $observation, 'indicator' => $indicator->refresh()->append('monitoring_status')], JsonResponse::HTTP_CREATED);
    }

    public function defineOnboardingRequirement(StoreThirdPartyOnboardingRequirementRequest $request, ThirdPartyEngagement $engagement, ThirdPartyEngagementOnboardingManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->define($request->user(), $engagement, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function completeOnboardingRequirement(CompleteThirdPartyOnboardingRequirementRequest $request, ThirdPartyEngagementOnboardingRequirement $requirement, ThirdPartyEngagementOnboardingManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->complete($request->user(), $requirement, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function reviewOnboardingReadiness(StoreThirdPartyOnboardingReadinessReviewRequest $request, ThirdPartyEngagement $engagement, ThirdPartyEngagementOnboardingManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->review($request->user(), $engagement, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function onboardingRequirements(ShowThirdPartyEngagementRequest $request, ThirdPartyEngagement $engagement): JsonResponse
    {
        return response()->json($engagement->onboardingRequirements()->with(['owner:id,name', 'definer:id,name', 'completions.completer:id,name'])->latest('version')->paginate($request->integer('per_page', 50)));
    }

    public function onboardingReadinessReviews(ShowThirdPartyEngagementRequest $request, ThirdPartyEngagement $engagement): JsonResponse
    {
        return response()->json($engagement->onboardingReadinessReviews()->with('reviewer:id,name')->latest('version')->paginate($request->integer('per_page', 50)));
    }

    public function defineOffboardingRequirement(StoreThirdPartyOffboardingRequirementRequest $request, ThirdPartyEngagement $engagement, ThirdPartyEngagementOffboardingManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->define($request->user(), $engagement, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function completeOffboardingRequirement(CompleteThirdPartyOffboardingRequirementRequest $request, ThirdPartyEngagementOffboardingRequirement $requirement, ThirdPartyEngagementOffboardingManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->complete($request->user(), $requirement, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function reviewOffboardingReadiness(StoreThirdPartyOffboardingReadinessReviewRequest $request, ThirdPartyEngagement $engagement, ThirdPartyEngagementOffboardingManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->review($request->user(), $engagement, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function offboardingRequirements(ShowThirdPartyEngagementRequest $request, ThirdPartyEngagement $engagement): JsonResponse
    {
        return response()->json($engagement->offboardingRequirements()->with(['owner:id,name', 'definer:id,name', 'completions.completer:id,name'])->latest('version')->paginate($request->integer('per_page', 50)));
    }

    public function offboardingReadinessReviews(ShowThirdPartyEngagementRequest $request, ThirdPartyEngagement $engagement): JsonResponse
    {
        return response()->json($engagement->offboardingReadinessReviews()->with('reviewer:id,name')->latest('version')->paginate($request->integer('per_page', 50)));
    }

    public function openCollaborationRequest(StoreThirdPartyCollaborationRequest $request, ThirdPartyEngagement $engagement, ThirdPartyEngagementCollaborationManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->open($request->user(), $engagement, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function decideCollaborationRequest(DecideThirdPartyCollaborationRequest $request, ThirdPartyEngagementCollaborationRequest $collaborationRequest, ThirdPartyEngagementCollaborationManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->decide($request->user(), $collaborationRequest, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function collaborationRequests(ShowThirdPartyEngagementRequest $request, ThirdPartyEngagement $engagement): JsonResponse
    {
        $history = $engagement->collaborationRequests()->with(['recipient:id,vendor_id,name,email', 'opener:id,name,email', 'events.evidence.document'])->latest('version')->paginate($request->integer('per_page', 50));
        $history->setCollection(app(ThirdPartyEngagementCollaborationManager::class)->visibleRequests($history->getCollection(), $request->user()));

        return response()->json($history);
    }

    public function fourthPartyDependencies(ListVendorFourthPartyDependenciesRequest $request, Vendor $vendor, FourthPartyDependencyManager $manager): JsonResponse
    {
        return response()->json($manager->history($vendor, $request->user())->paginate($request->integer('per_page', 50)));
    }

    public function recordFourthPartyDependency(StoreFourthPartyDependencyRequest $request, Vendor $vendor, FourthPartyDependencyManager $manager): JsonResponse
    {
        $record = $manager->record($vendor, $request->user(), $request->validated());

        return response()->json(['data' => $record, 'concentration' => $manager->vendorConcentration($record)], JsonResponse::HTTP_CREATED);
    }

    public function fourthPartyConcentrations(ListFourthPartyConcentrationsRequest $request, FourthPartyDependencyManager $manager): JsonResponse
    {
        return response()->json($manager->concentrations($request->user(), $request->integer('per_page', 50), $request->integer('page', 1)));
    }

    public function assess(StoreVendorRiskAssessmentRequest $request, Vendor $vendor, ThirdPartyRiskManager $manager): JsonResponse
    {
        $assessment = $manager->assess($vendor, $request->user(), $request->validated());

        return response()->json(['data' => $assessment, 'vendor' => $vendor->refresh()->append('third_party_risk_status')], JsonResponse::HTTP_CREATED);
    }

    public function mapRisk(MapVendorRiskRequest $request, Vendor $vendor, ThirdPartyRiskManager $manager): JsonResponse
    {
        $risk = $manager->mapRisk($vendor, Risk::query()->findOrFail($request->integer('risk_id')));

        return response()->json(['data' => $risk, 'vendor' => $vendor->refresh()->append('third_party_risk_status')], JsonResponse::HTTP_CREATED);
    }

    public function decide(StoreVendorRiskDecisionRequest $request, Vendor $vendor, ThirdPartyRiskManager $manager): JsonResponse
    {
        $data = $request->validated();
        $decision = $manager->decide($vendor, $request->user(), ThirdPartyRiskDecisionType::from($data['decision']), $data);

        return response()->json(['data' => $decision, 'vendor' => $vendor->refresh()->append('third_party_risk_status')], JsonResponse::HTTP_CREATED);
    }

    public function review(StoreVendorRiskReviewRequest $request, Vendor $vendor, ThirdPartyRiskManager $manager): JsonResponse
    {
        $data = $request->validated();
        $review = $manager->review($vendor, $request->user(), ThirdPartyRiskReviewOutcome::from($data['outcome']), $data);

        return response()->json(['data' => $review, 'vendor' => $vendor->refresh()->append('third_party_risk_status')], JsonResponse::HTTP_CREATED);
    }
}
