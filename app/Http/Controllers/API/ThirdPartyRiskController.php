<?php

namespace App\Http\Controllers\API;

use App\Enums\ThirdPartyRiskDecisionType;
use App\Enums\ThirdPartyRiskReviewOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\MapVendorRiskRequest;
use App\Http\Requests\StoreVendorRiskAssessmentRequest;
use App\Http\Requests\StoreVendorRiskDecisionRequest;
use App\Http\Requests\StoreVendorRiskReviewRequest;
use App\Models\Risk;
use App\Models\Vendor;
use App\ThirdPartyRisk\ThirdPartyRiskManager;
use Illuminate\Http\JsonResponse;

class ThirdPartyRiskController extends Controller
{
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
