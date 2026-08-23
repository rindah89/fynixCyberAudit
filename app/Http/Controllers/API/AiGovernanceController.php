<?php

namespace App\Http\Controllers\API;

use App\AiGovernance\AiGovernanceManager;
use App\Enums\AiGovernanceDecisionType;
use App\Enums\AiMonitoringOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\MapAiUseCaseControlRequest;
use App\Http\Requests\MapAiUseCaseRiskRequest;
use App\Http\Requests\StoreAiGovernanceDecisionRequest;
use App\Http\Requests\StoreAiMonitoringReviewRequest;
use App\Http\Requests\StoreAiRiskAssessmentRequest;
use App\Http\Requests\StoreAiSystemRequest;
use App\Http\Requests\StoreAiUseCaseRequest;
use App\Models\AiSystem;
use App\Models\AiUseCase;
use Illuminate\Http\JsonResponse;

class AiGovernanceController extends Controller
{
    public function storeSystem(StoreAiSystemRequest $request): JsonResponse
    {
        $system = AiSystem::create($request->validated());

        return response()->json(['data' => $system->load(['owner', 'vendor', 'application'])], JsonResponse::HTTP_CREATED);
    }

    public function storeUseCase(StoreAiUseCaseRequest $request, AiSystem $system): JsonResponse
    {
        $useCase = $system->useCases()->create($request->validated());

        return response()->json(['data' => $useCase, 'system' => $system->refresh()], JsonResponse::HTTP_CREATED);
    }

    public function storeAssessment(StoreAiRiskAssessmentRequest $request, AiUseCase $useCase, AiGovernanceManager $manager): JsonResponse
    {
        $assessment = $manager->assess($useCase, $request->user(), $request->validated());

        return response()->json(['data' => $assessment, 'use_case' => $useCase->refresh()], JsonResponse::HTTP_CREATED);
    }

    public function mapControl(MapAiUseCaseControlRequest $request, AiUseCase $useCase): JsonResponse
    {
        $useCase->controls()->syncWithoutDetaching([$request->integer('control_id')]);

        return response()->json(['data' => $useCase->controls()->findOrFail($request->integer('control_id')), 'use_case' => $useCase->refresh()], JsonResponse::HTTP_CREATED);
    }

    public function mapRisk(MapAiUseCaseRiskRequest $request, AiUseCase $useCase): JsonResponse
    {
        $useCase->risks()->syncWithoutDetaching([$request->integer('risk_id')]);

        return response()->json(['data' => $useCase->risks()->findOrFail($request->integer('risk_id')), 'use_case' => $useCase->refresh()], JsonResponse::HTTP_CREATED);
    }

    public function decide(StoreAiGovernanceDecisionRequest $request, AiUseCase $useCase, AiGovernanceManager $manager): JsonResponse
    {
        $data = $request->validated();
        $decision = $manager->decide($useCase, $request->user(), AiGovernanceDecisionType::from($data['decision']), $data);

        return response()->json(['data' => $decision, 'use_case' => $useCase->refresh()], JsonResponse::HTTP_CREATED);
    }

    public function monitor(StoreAiMonitoringReviewRequest $request, AiUseCase $useCase, AiGovernanceManager $manager): JsonResponse
    {
        $data = $request->validated();
        $review = $manager->monitor($useCase, $request->user(), AiMonitoringOutcome::from($data['outcome']), $data);

        return response()->json(['data' => $review, 'use_case' => $useCase->refresh()], JsonResponse::HTTP_CREATED);
    }
}
