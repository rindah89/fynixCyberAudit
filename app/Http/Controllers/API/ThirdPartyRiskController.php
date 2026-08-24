<?php

namespace App\Http\Controllers\API;

use App\Enums\ThirdPartyRiskDecisionType;
use App\Enums\ThirdPartyRiskReviewOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListFourthPartyConcentrationsRequest;
use App\Http\Requests\ListVendorFourthPartyDependenciesRequest;
use App\Http\Requests\MapVendorRiskRequest;
use App\Http\Requests\StoreFourthPartyDependencyRequest;
use App\Http\Requests\StoreVendorRiskAssessmentRequest;
use App\Http\Requests\StoreVendorRiskDecisionRequest;
use App\Http\Requests\StoreVendorRiskReviewRequest;
use App\Models\Risk;
use App\Models\Vendor;
use App\Services\FourthPartyDependencyManager;
use App\ThirdPartyRisk\ThirdPartyRiskManager;
use Illuminate\Http\JsonResponse;

class ThirdPartyRiskController extends Controller
{
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
