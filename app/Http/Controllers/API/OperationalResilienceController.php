<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteRecoveryExerciseRequest;
use App\Http\Requests\ListContinuityActivationsRequest;
use App\Http\Requests\ShowContinuityActivationRequest;
use App\Http\Requests\StoreBusinessImpactAnalysisRequest;
use App\Http\Requests\StoreBusinessServiceDependencyRequest;
use App\Http\Requests\StoreBusinessServiceRequest;
use App\Http\Requests\StoreContinuityActivationRequest;
use App\Http\Requests\StoreRecoveryExerciseRequest;
use App\Http\Requests\StoreRecoveryPlanRequest;
use App\Http\Requests\TransitionContinuityActivationRequest;
use App\Models\BusinessService;
use App\Models\ContinuityActivation;
use App\Models\RecoveryExercise;
use App\Models\RecoveryPlan;
use App\OperationalResilience\ContinuityActivationManager;
use App\OperationalResilience\ResilienceManager;
use Illuminate\Http\JsonResponse;

class OperationalResilienceController extends Controller
{
    public function continuityActivations(ListContinuityActivationsRequest $request, BusinessService $service): JsonResponse
    {
        return response()->json($service->continuityActivations()->with(['activator:id,name', 'events.recorder:id,name'])->latest('started_at')->paginate($request->integer('per_page', 25)));
    }

    public function showContinuityActivation(ShowContinuityActivationRequest $request, ContinuityActivation $activation): JsonResponse
    {
        return response()->json(['data' => $activation->load(['activator:id,name', 'events.recorder:id,name'])]);
    }

    public function activateContinuity(StoreContinuityActivationRequest $request, RecoveryPlan $plan, ContinuityActivationManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->activate($request->user(), $plan, $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function transitionContinuity(TransitionContinuityActivationRequest $request, ContinuityActivation $activation, ContinuityActivationManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->transition($request->user(), $activation, $request->validated())]);
    }

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
