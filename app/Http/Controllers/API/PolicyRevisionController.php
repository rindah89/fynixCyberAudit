<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListPolicyRevisionsRequest;
use App\Http\Requests\ReviewPolicyRevisionRequest;
use App\Http\Requests\SubmitPolicyRevisionRequest;
use App\Models\Policy;
use App\Models\PolicyRevision;
use App\PolicyCompliance\PolicyRevisionManager;
use Illuminate\Http\JsonResponse;

class PolicyRevisionController extends Controller
{
    public function index(ListPolicyRevisionsRequest $request, Policy $policy, PolicyRevisionManager $manager): JsonResponse
    {
        return response()->json($manager->history($policy, $request->user())->paginate($request->integer('per_page', 50)));
    }

    public function store(SubmitPolicyRevisionRequest $request, Policy $policy, PolicyRevisionManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->submit($policy, $request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function review(ReviewPolicyRevisionRequest $request, PolicyRevision $revision, PolicyRevisionManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->review($revision, $request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }
}
