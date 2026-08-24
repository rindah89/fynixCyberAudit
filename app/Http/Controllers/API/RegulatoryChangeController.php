<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssessRegulatoryChangeRequest;
use App\Http\Requests\ListRegulatoryRequirementsRequest;
use App\Http\Requests\PublishRegulatoryRequirementVersionRequest;
use App\Http\Requests\StoreRegulatoryRequirementRequest;
use App\Http\Requests\StoreRegulatorySourceRequest;
use App\Http\Requests\UpdateRegulatorySourceRequest;
use App\Models\RegulatoryRequirement;
use App\Models\RegulatoryRequirementVersion;
use App\Models\RegulatorySource;
use App\PolicyCompliance\RegulatoryChangeManager;
use Illuminate\Http\JsonResponse;

class RegulatoryChangeController extends Controller
{
    public function storeSource(StoreRegulatorySourceRequest $request, RegulatoryChangeManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->createSource($request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function updateSource(UpdateRegulatorySourceRequest $request, RegulatorySource $source, RegulatoryChangeManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->updateSource($source, $request->user(), $request->validated())]);
    }

    public function storeRequirement(StoreRegulatoryRequirementRequest $request, RegulatorySource $source, RegulatoryChangeManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->createRequirement($source, $request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function publish(PublishRegulatoryRequirementVersionRequest $request, RegulatoryRequirement $requirement, RegulatoryChangeManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->publishVersion($requirement, $request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function assess(AssessRegulatoryChangeRequest $request, RegulatoryRequirementVersion $version, RegulatoryChangeManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->assess($version, $request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function index(ListRegulatoryRequirementsRequest $request, RegulatoryChangeManager $manager): JsonResponse
    {
        $requirements = $manager->requirements($request->user())->paginate($request->integer('per_page', 50));
        $requirements->through(fn (RegulatoryRequirement $requirement) => $requirement->append('governance_status'));

        return response()->json($requirements);
    }

    public function versions(ListRegulatoryRequirementsRequest $request, RegulatoryRequirement $requirement, RegulatoryChangeManager $manager): JsonResponse
    {
        return response()->json($manager->versionHistory($requirement, $request->user())
            ->paginate($request->integer('per_page', 50)));
    }

    public function assessments(ListRegulatoryRequirementsRequest $request, RegulatoryRequirement $requirement, RegulatoryChangeManager $manager): JsonResponse
    {
        return response()->json($manager->assessmentHistory($requirement, $request->user())
            ->paginate($request->integer('per_page', 50)));
    }
}
