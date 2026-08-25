<?php

namespace App\Http\Controllers\API;

use App\ComplianceCases\ComplianceCaseIntakeManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\DecideComplianceCaseIntakeRequest;
use App\Http\Requests\ListComplianceCaseIntakesRequest;
use App\Http\Requests\ListMyComplianceCaseIntakesRequest;
use App\Http\Requests\StoreComplianceCaseIntakeRequest;
use App\Models\ComplianceCaseIntake;
use Illuminate\Http\JsonResponse;

class ComplianceCaseIntakeController extends Controller
{
    public function index(ListComplianceCaseIntakesRequest $request, ComplianceCaseIntakeManager $manager): JsonResponse
    {
        return response()->json($manager->managerHistory($request->integer('per_page', 50)));
    }

    public function mine(ListMyComplianceCaseIntakesRequest $request, ComplianceCaseIntakeManager $manager): JsonResponse
    {
        return response()->json($manager->reporterHistory($request->user(), $request->integer('per_page', 50)));
    }

    public function store(StoreComplianceCaseIntakeRequest $request, ComplianceCaseIntakeManager $manager): JsonResponse
    {
        $intake = $manager->submit($request->user(), $request->validated())->load('decision');

        return response()->json(['data' => $manager->reporterProjection($intake)], JsonResponse::HTTP_CREATED);
    }

    public function decide(DecideComplianceCaseIntakeRequest $request, ComplianceCaseIntake $intake, ComplianceCaseIntakeManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->decide($request->user(), $intake, $request->validated())], JsonResponse::HTTP_CREATED);
    }
}
