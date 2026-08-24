<?php

namespace App\Http\Controllers\API;

use App\Esg\EsgMaterialityManager;
use App\Esg\EsgPerformanceManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListEsgTopicsRequest;
use App\Http\Requests\ReviseEsgTopicRequest;
use App\Http\Requests\ShowEsgGoalRequest;
use App\Http\Requests\ShowEsgKpiRequest;
use App\Http\Requests\ShowEsgTopicRequest;
use App\Http\Requests\StoreEsgGoalRequest;
use App\Http\Requests\StoreEsgKpiObservationRequest;
use App\Http\Requests\StoreEsgKpiRequest;
use App\Http\Requests\StoreEsgMaterialityAssessmentRequest;
use App\Http\Requests\StoreEsgTopicRequest;
use App\Models\EsgGoal;
use App\Models\EsgKpi;
use App\Models\EsgMaterialTopic;
use Illuminate\Http\JsonResponse;

class EsgManagementController extends Controller
{
    public function index(ListEsgTopicsRequest $r): JsonResponse
    {
        $q = EsgMaterialTopic::query()->with(['owner:id,name', 'latestVersion', 'latestAssessment'])->latest('id');
        if (! $r->user()->can('Read ESG') && ! $r->user()->can('Manage ESG') && ! $r->user()->can('Assess ESG') && ! $r->user()->can('Validate ESG Data') && ! $r->user()->can('Approve ESG Disclosures')) {
            $q->where(function ($scope) use ($r): void {
                $scope->where('owner_id', $r->user()->id)
                    ->orWhereHas('goals', fn ($goal) => $goal->where('owner_id', $r->user()->id))
                    ->orWhereHas('goals.kpis', fn ($kpi) => $kpi->where('owner_id', $r->user()->id));
            });
        }

        return response()->json($q->paginate($r->integer('per_page', 50)));
    }

    public function store(StoreEsgTopicRequest $r, EsgMaterialityManager $m): JsonResponse
    {
        return response()->json(['data' => $m->register($r->user(), $r->validated())], 201);
    }

    public function show(ShowEsgTopicRequest $r, EsgMaterialTopic $topic): JsonResponse
    {
        return response()->json(['data' => $topic->load(['owner:id,name,email', 'latestVersion', 'latestAssessment'])]);
    }

    public function revise(ReviseEsgTopicRequest $r, EsgMaterialTopic $topic, EsgMaterialityManager $m): JsonResponse
    {
        return response()->json(['data' => $m->revise($r->user(), $topic, $r->validated()), 'topic' => $topic->refresh()->load('owner:id,name')]);
    }

    public function versions(ShowEsgTopicRequest $r, EsgMaterialTopic $topic): JsonResponse
    {
        return response()->json($topic->versions()->with('actor:id,name')->paginate($r->integer('per_page', 50)));
    }

    public function assessments(ShowEsgTopicRequest $r, EsgMaterialTopic $topic): JsonResponse
    {
        return response()->json($topic->assessments()->with(['assessor:id,name', 'topicVersion:id,version,fingerprint'])->paginate($r->integer('per_page', 50)));
    }

    public function assess(StoreEsgMaterialityAssessmentRequest $r, EsgMaterialTopic $topic, EsgMaterialityManager $m): JsonResponse
    {
        return response()->json(['data' => $m->assess($r->user(), $topic, $r->validated())], 201);
    }

    public function goals(ShowEsgTopicRequest $request, EsgMaterialTopic $topic): JsonResponse
    {
        return response()->json($topic->goals()->paginate($request->integer('per_page', 50)));
    }

    public function createGoal(StoreEsgGoalRequest $request, EsgMaterialTopic $topic, EsgPerformanceManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->createGoal($request->user(), $topic, $request->validated())], 201);
    }

    public function showGoal(ShowEsgGoalRequest $request, EsgGoal $goal): JsonResponse
    {
        return response()->json(['data' => $goal->load(['topic', 'owner:id,name,email', 'creator:id,name,email'])]);
    }

    public function kpis(ShowEsgGoalRequest $request, EsgGoal $goal): JsonResponse
    {
        return response()->json($goal->kpis()->paginate($request->integer('per_page', 50)));
    }

    public function createKpi(StoreEsgKpiRequest $request, EsgGoal $goal, EsgPerformanceManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->defineKpi($request->user(), $goal, $request->validated())], 201);
    }

    public function showKpi(ShowEsgKpiRequest $request, EsgKpi $kpi): JsonResponse
    {
        return response()->json(['data' => $kpi->load(['goal.topic', 'owner:id,name,email', 'creator:id,name,email', 'latestObservation.observer:id,name'])]);
    }

    public function observations(ShowEsgKpiRequest $request, EsgKpi $kpi): JsonResponse
    {
        return response()->json($kpi->observations()->paginate($request->integer('per_page', 50)));
    }

    public function observe(StoreEsgKpiObservationRequest $request, EsgKpi $kpi, EsgPerformanceManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->observe($request->user(), $kpi, $request->validated())], 201);
    }
}
