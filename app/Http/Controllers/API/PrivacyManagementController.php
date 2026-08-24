<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListPrivacyActivitiesRequest;
use App\Http\Requests\RevisePrivacyActivityRequest;
use App\Http\Requests\ShowPrivacyActivityRequest;
use App\Http\Requests\StorePrivacyActivityRequest;
use App\Http\Requests\StorePrivacyAssessmentRequest;
use App\Models\PrivacyProcessingActivity;
use App\Privacy\PrivacyManagementManager;
use Illuminate\Http\JsonResponse;

class PrivacyManagementController extends Controller
{
    public function index(ListPrivacyActivitiesRequest $request): JsonResponse
    {
        $query = PrivacyProcessingActivity::query()->with('owner:id,name')->withCount(['versions', 'assessments'])->latest('id');
        if (! $request->user()->can('Read Privacy') && ! $request->user()->can('Manage Privacy') && ! $request->user()->can('Assess Privacy')) {
            $query->where('owner_id', $request->user()->id);
        }

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }

    public function store(StorePrivacyActivityRequest $request, PrivacyManagementManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->register($request->user(), $request->validated())], 201);
    }

    public function show(ShowPrivacyActivityRequest $request, PrivacyProcessingActivity $activity): JsonResponse
    {
        return response()->json(['data' => $activity->load('owner:id,name,email')->loadCount(['versions', 'assessments'])]);
    }

    public function revise(RevisePrivacyActivityRequest $request, PrivacyProcessingActivity $activity, PrivacyManagementManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->revise($request->user(), $activity, $request->validated()), 'activity' => $activity->refresh()->load('owner:id,name')]);
    }

    public function assess(StorePrivacyAssessmentRequest $request, PrivacyProcessingActivity $activity, PrivacyManagementManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->assess($request->user(), $activity, $request->validated())], 201);
    }

    public function versions(ShowPrivacyActivityRequest $request, PrivacyProcessingActivity $activity): JsonResponse
    {
        return response()->json($activity->versions()->with('actor:id,name')->paginate($request->integer('per_page', 50)));
    }

    public function assessments(ShowPrivacyActivityRequest $request, PrivacyProcessingActivity $activity): JsonResponse
    {
        return response()->json($activity->assessments()->with(['assessor:id,name', 'activityVersion:id,version,fingerprint'])->paginate($request->integer('per_page', 50)));
    }
}
