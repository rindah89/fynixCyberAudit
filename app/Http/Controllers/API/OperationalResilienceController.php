<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteRecoveryExerciseRequest;
use App\Http\Requests\StoreBusinessImpactAnalysisRequest;
use App\Http\Requests\StoreBusinessServiceDependencyRequest;
use App\Http\Requests\StoreBusinessServiceRequest;
use App\Http\Requests\StoreRecoveryExerciseRequest;
use App\Http\Requests\StoreRecoveryPlanRequest;
use App\Models\BusinessService;
use App\Models\RecoveryExercise;
use App\Models\RecoveryPlan;
use App\OperationalResilience\ResilienceManager;
use Illuminate\Http\JsonResponse;

class OperationalResilienceController extends Controller
{
    public function storeService(StoreBusinessServiceRequest $request): JsonResponse
    {
        $service = BusinessService::create($request->validated());

        return response()->json(['data' => $service->load('owner')], JsonResponse::HTTP_CREATED);
    }

    public function storeImpactAnalysis(StoreBusinessImpactAnalysisRequest $request, BusinessService $service, ResilienceManager $manager): JsonResponse
    {
        $analysis = $manager->createImpactAnalysis($service, $request->user(), $request->validated());

        return response()->json(['data' => $analysis, 'service' => $service->refresh()], JsonResponse::HTTP_CREATED);
    }

    public function storeDependency(StoreBusinessServiceDependencyRequest $request, BusinessService $service): JsonResponse
    {
        $dependency = $service->dependencies()->create($request->validated())->load(['dependentService', 'application', 'asset', 'vendor', 'control']);

        return response()->json(['data' => $dependency], JsonResponse::HTTP_CREATED);
    }

    public function storePlan(StoreRecoveryPlanRequest $request, BusinessService $service, ResilienceManager $manager): JsonResponse
    {
        $plan = $manager->createRecoveryPlan($service, $request->user(), $request->validated());

        return response()->json(['data' => $plan], JsonResponse::HTTP_CREATED);
    }

    public function storeExercise(StoreRecoveryExerciseRequest $request, RecoveryPlan $plan): JsonResponse
    {
        $exercise = $plan->exercises()->create($request->validated() + ['facilitator_id' => $request->user()->id]);

        return response()->json(['data' => $exercise], JsonResponse::HTTP_CREATED);
    }

    public function completeExercise(CompleteRecoveryExerciseRequest $request, RecoveryExercise $exercise, ResilienceManager $manager): JsonResponse
    {
        $completed = $manager->completeExercise($exercise, $request->user(), $request->validated());

        return response()->json(['data' => $completed, 'service' => $completed->recoveryPlan->businessService->refresh()]);
    }
}
