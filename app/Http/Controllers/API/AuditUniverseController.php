<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveAuditPlanRequest;
use App\Http\Requests\AssessAuditableEntityRequest;
use App\Http\Requests\DeleteAuditPlanItemRequest;
use App\Http\Requests\LaunchAuditEngagementRequest;
use App\Http\Requests\ListAuditUniverseRequest;
use App\Http\Requests\StoreAuditableEntityRequest;
use App\Http\Requests\StoreAuditPlanItemRequest;
use App\Http\Requests\StoreAuditPlanRequest;
use App\Http\Requests\UpdateAuditableEntityRequest;
use App\Http\Requests\UpdateAuditPlanItemRequest;
use App\Models\AuditableEntity;
use App\Models\AuditPlan;
use App\Models\AuditPlanItem;
use App\Services\AuditEngagementManager;
use App\Services\AuditUniverseManager;
use Illuminate\Http\JsonResponse;

class AuditUniverseController extends Controller
{
    public function entities(ListAuditUniverseRequest $request, AuditUniverseManager $manager): JsonResponse
    {
        $entities = $manager->entities($request->user())->paginate($request->integer('per_page', 50));
        $entities->through(fn (AuditableEntity $entity) => $entity->append('planning_status'));

        return response()->json($entities);
    }

    public function storeEntity(StoreAuditableEntityRequest $request, AuditUniverseManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->createEntity($request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function updateEntity(UpdateAuditableEntityRequest $request, AuditableEntity $entity, AuditUniverseManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->updateEntity($entity, $request->user(), $request->validated())]);
    }

    public function assess(AssessAuditableEntityRequest $request, AuditableEntity $entity, AuditUniverseManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->assess($entity, $request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function assessments(ListAuditUniverseRequest $request, AuditableEntity $entity, AuditUniverseManager $manager): JsonResponse
    {
        return response()->json($manager->assessmentHistory($entity, $request->user())->paginate($request->integer('per_page', 50)));
    }

    public function plans(ListAuditUniverseRequest $request, AuditUniverseManager $manager): JsonResponse
    {
        return response()->json($manager->plans($request->user())->paginate($request->integer('per_page', 50)));
    }

    public function storePlan(StoreAuditPlanRequest $request, AuditUniverseManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->createPlan($request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function addItem(StoreAuditPlanItemRequest $request, AuditPlan $plan, AuditUniverseManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->addPlanItem($plan, $request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function items(ListAuditUniverseRequest $request, AuditPlan $plan, AuditUniverseManager $manager): JsonResponse
    {
        return response()->json($manager->planItems($plan, $request->user())->paginate($request->integer('per_page', 50)));
    }

    public function updateItem(UpdateAuditPlanItemRequest $request, AuditPlan $plan, AuditPlanItem $item, AuditUniverseManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->updatePlanItem($plan, $item, $request->user(), $request->validated())]);
    }

    public function removeItem(DeleteAuditPlanItemRequest $request, AuditPlan $plan, AuditPlanItem $item, AuditUniverseManager $manager): JsonResponse
    {
        $manager->removePlanItem($plan, $item, $request->user());

        return response()->json(null, JsonResponse::HTTP_NO_CONTENT);
    }

    public function approve(ApproveAuditPlanRequest $request, AuditPlan $plan, AuditUniverseManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->approvePlan($plan, $request->user())]);
    }

    public function launchEngagement(LaunchAuditEngagementRequest $request, AuditPlanItem $item, AuditEngagementManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->launch($item, $request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }
}
