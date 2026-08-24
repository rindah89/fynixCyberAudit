<?php

namespace App\Http\Controllers\API;

use App\Esg\EsgMaterialityManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListEsgTopicsRequest;
use App\Http\Requests\ReviseEsgTopicRequest;
use App\Http\Requests\ShowEsgTopicRequest;
use App\Http\Requests\StoreEsgMaterialityAssessmentRequest;
use App\Http\Requests\StoreEsgTopicRequest;
use App\Models\EsgMaterialTopic;
use Illuminate\Http\JsonResponse;

class EsgManagementController extends Controller
{
    public function index(ListEsgTopicsRequest $r): JsonResponse
    {
        $q = EsgMaterialTopic::query()->with(['owner:id,name', 'latestVersion', 'latestAssessment'])->latest('id');
        if (! $r->user()->can('Read ESG') && ! $r->user()->can('Manage ESG') && ! $r->user()->can('Assess ESG')) {
            $q->where('owner_id', $r->user()->id);
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
}
