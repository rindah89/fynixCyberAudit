<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListSystemAuthorizationPackagesRequest;
use App\Http\Requests\ShowSystemAuthorizationPackageRequest;
use App\Http\Requests\StoreSystemAuthorizationDecisionRequest;
use App\Http\Requests\StoreSystemAuthorizationMonitoringRequest;
use App\Http\Requests\StoreSystemAuthorizationPackageRequest;
use App\Models\Application;
use App\Models\SystemAuthorizationPackage;
use App\SystemAuthorization\SystemAuthorizationManager;
use Illuminate\Http\JsonResponse;

class SystemAuthorizationController extends Controller
{
    public function index(ListSystemAuthorizationPackagesRequest $request): JsonResponse
    {
        return response()->json(SystemAuthorizationPackage::query()->with(['application:id,name,owner_id', 'submitter:id,name', 'latestDecision.authorizer:id,name', 'latestMonitoringReview'])->latest('id')->paginate($request->integer('per_page', 50)));
    }

    public function store(StoreSystemAuthorizationPackageRequest $request, Application $application, SystemAuthorizationManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->submit($request->user(), $application, $request->validated())], 201);
    }

    public function show(ShowSystemAuthorizationPackageRequest $request, SystemAuthorizationPackage $package): JsonResponse
    {
        return response()->json(['data' => $package->load(['application.owner:id,name,email', 'submitter:id,name', 'decisions.authorizer:id,name', 'latestDecision', 'monitoringReviews.reviewer:id,name', 'latestMonitoringReview'])]);
    }

    public function decisions(ShowSystemAuthorizationPackageRequest $request, SystemAuthorizationPackage $package): JsonResponse
    {
        return response()->json($package->decisions()->with('authorizer:id,name')->paginate($request->integer('per_page', 50)));
    }

    public function decide(StoreSystemAuthorizationDecisionRequest $request, SystemAuthorizationPackage $package, SystemAuthorizationManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->decide($request->user(), $package, $request->validated())], 201);
    }

    public function monitoring(ShowSystemAuthorizationPackageRequest $request, SystemAuthorizationPackage $package): JsonResponse
    {
        return response()->json($package->monitoringReviews()->paginate($request->integer('per_page', 50)));
    }

    public function monitor(StoreSystemAuthorizationMonitoringRequest $request, SystemAuthorizationPackage $package, SystemAuthorizationManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->monitor($request->user(), $package, $request->validated())], 201);
    }
}
