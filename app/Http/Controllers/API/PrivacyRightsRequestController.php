<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListPrivacyRightsRequestsRequest;
use App\Http\Requests\ShowPrivacyRightsRequestRequest;
use App\Http\Requests\StorePrivacyRightsRequest;
use App\Http\Requests\TransitionPrivacyRightsRequest;
use App\Models\PrivacyRightsRequest;
use App\Privacy\PrivacyRightsRequestManager;
use Illuminate\Http\JsonResponse;

class PrivacyRightsRequestController extends Controller
{
    public function index(ListPrivacyRightsRequestsRequest $request): JsonResponse
    {
        $query = PrivacyRightsRequest::query()->with(['assignee:id,name', 'opener:id,name'])->withCount('events')->latest('received_at');
        if (! $request->user()->can('Read Privacy Rights') && ! $request->user()->can('Manage Privacy Rights')) {
            $query->where('assigned_to', $request->user()->id);
        }

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }

    public function store(StorePrivacyRightsRequest $request, PrivacyRightsRequestManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->open($request->user(), $request->validated())], 201);
    }

    public function show(ShowPrivacyRightsRequestRequest $request, PrivacyRightsRequest $rightsRequest): JsonResponse
    {
        return response()->json(['data' => $rightsRequest->load(['assignee:id,name,email', 'opener:id,name', 'events.actor:id,name'])]);
    }

    public function events(ShowPrivacyRightsRequestRequest $request, PrivacyRightsRequest $rightsRequest): JsonResponse
    {
        return response()->json($rightsRequest->events()->with('actor:id,name')->paginate($request->integer('per_page', 50)));
    }

    public function transition(TransitionPrivacyRightsRequest $request, PrivacyRightsRequest $rightsRequest, PrivacyRightsRequestManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->transition($request->user(), $rightsRequest, $request->validated())]);
    }
}
