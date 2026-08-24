<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\DecidePolicyExceptionRequest;
use App\Http\Requests\ListGovernedPolicyExceptionsRequest;
use App\Http\Requests\SubmitPolicyExceptionRequest;
use App\Models\Policy;
use App\Models\PolicyException;
use App\PolicyCompliance\PolicyExceptionGovernanceManager;
use Illuminate\Http\JsonResponse;

class PolicyExceptionGovernanceController extends Controller
{
    public function index(ListGovernedPolicyExceptionsRequest $request, Policy $policy, PolicyExceptionGovernanceManager $manager): JsonResponse
    {
        return response()->json($manager->history($policy, $request->user())->paginate($request->integer('per_page', 50)));
    }

    public function store(SubmitPolicyExceptionRequest $request, Policy $policy, PolicyExceptionGovernanceManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->submit($policy, $request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function decide(DecidePolicyExceptionRequest $request, PolicyException $exception, PolicyExceptionGovernanceManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->decide($exception, $request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }
}
