<?php

namespace App\Http\Controllers\API;

use App\Enums\GovernanceIssueType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CloseGovernanceIssueRequest;
use App\Http\Requests\ShowGovernanceIssueLifecycleRequest;
use App\Http\Requests\StoreGovernanceIssueRemediationRequest;
use App\Http\Requests\TransitionGovernanceIssueRequest;
use App\Services\GovernanceIssueLifecycleManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

class GovernanceIssueLifecycleController extends Controller
{
    public function show(ShowGovernanceIssueLifecycleRequest $request, string $type, int $issue, GovernanceIssueLifecycleManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->show($this->issue($manager, $type, $issue), $request->user())]);
    }

    public function remediation(StoreGovernanceIssueRemediationRequest $request, string $type, int $issue, GovernanceIssueLifecycleManager $manager): JsonResponse
    {
        $lifecycle = $manager->handoff($this->issue($manager, $type, $issue), $request->user(), $request->validated());

        return response()->json(['data' => $lifecycle], JsonResponse::HTTP_CREATED);
    }

    public function requestVerification(TransitionGovernanceIssueRequest $request, string $type, int $issue, GovernanceIssueLifecycleManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->requestVerification($this->issue($manager, $type, $issue), $request->user(), $request->validated('rationale'))]);
    }

    public function close(CloseGovernanceIssueRequest $request, string $type, int $issue, GovernanceIssueLifecycleManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->close($this->issue($manager, $type, $issue), $request->user(), $request->validated())]);
    }

    public function reopen(TransitionGovernanceIssueRequest $request, string $type, int $issue, GovernanceIssueLifecycleManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->reopen($this->issue($manager, $type, $issue), $request->user(), $request->validated('rationale'))]);
    }

    private function issue(GovernanceIssueLifecycleManager $manager, string $type, int $id): Model
    {
        abort_unless($issueType = GovernanceIssueType::tryFrom($type), 404);

        return $manager->resolve($issueType, $id);
    }
}
