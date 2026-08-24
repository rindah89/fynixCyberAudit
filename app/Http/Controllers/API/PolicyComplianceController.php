<?php

namespace App\Http\Controllers\API;

use App\Enums\PolicyAttestationOutcome;
use App\Enums\PolicyObligationFrequency;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcknowledgePolicyCampaignRequest;
use App\Http\Requests\ClosePolicyAcknowledgementCampaignRequest;
use App\Http\Requests\LaunchPolicyAcknowledgementCampaignRequest;
use App\Http\Requests\ListPolicyAcknowledgementsRequest;
use App\Http\Requests\StorePolicyAttestationRequest;
use App\Http\Requests\SubmitPolicyKnowledgeCheckRequest;
use App\Models\Policy;
use App\Models\PolicyAcknowledgementAssignment;
use App\Models\PolicyAcknowledgementCampaign;
use App\Models\PolicyException;
use App\Models\PolicyObligation;
use App\PolicyCompliance\PolicyAcknowledgementManager;
use App\PolicyCompliance\PolicyCompliance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PolicyComplianceController extends Controller
{
    public function launchAcknowledgementCampaign(LaunchPolicyAcknowledgementCampaignRequest $request, Policy $policy, PolicyAcknowledgementManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->launch($policy, $request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function myAcknowledgements(ListPolicyAcknowledgementsRequest $request, PolicyAcknowledgementManager $manager): JsonResponse
    {
        $assignments = $manager->assignments($request->user())->paginate($request->integer('per_page', 50));
        $assignments->through(fn (PolicyAcknowledgementAssignment $assignment) => $assignment->append('acknowledgement_status'));

        return response()->json($assignments);
    }

    public function acknowledgementReport(ListPolicyAcknowledgementsRequest $request, PolicyAcknowledgementCampaign $campaign, PolicyAcknowledgementManager $manager): JsonResponse
    {
        $assignments = $manager->report($campaign, $request->user())->paginate($request->integer('per_page', 50));
        $assignments->through(function (PolicyAcknowledgementAssignment $assignment): PolicyAcknowledgementAssignment {
            $assignment->append('acknowledgement_status');
            $assignment->knowledgeCheckAttempts->each->makeVisible('question_snapshot');

            return $assignment;
        });

        return response()->json($assignments);
    }

    public function acknowledge(AcknowledgePolicyCampaignRequest $request, PolicyAcknowledgementAssignment $assignment, PolicyAcknowledgementManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->acknowledge($assignment, $request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function submitKnowledgeCheck(SubmitPolicyKnowledgeCheckRequest $request, PolicyAcknowledgementAssignment $assignment, PolicyAcknowledgementManager $manager): JsonResponse
    {
        return response()->json([
            'data' => $manager->submitKnowledgeCheck($assignment, $request->user(), $request->validated('answers')),
        ], JsonResponse::HTTP_CREATED);
    }

    public function closeAcknowledgementCampaign(ClosePolicyAcknowledgementCampaignRequest $request, PolicyAcknowledgementCampaign $campaign, PolicyAcknowledgementManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->close($campaign, $request->user())->append('campaign_status')]);
    }

    public function store(Request $request, Policy $policy): JsonResponse
    {
        $this->authorize('update', $policy);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:255', Rule::unique('policy_obligations')->where('policy_id', $policy->id)],
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'control_id' => 'nullable|exists:controls,id',
            'owner_id' => 'required|exists:users,id',
            'frequency' => ['required', Rule::enum(PolicyObligationFrequency::class)],
            'next_due_at' => 'required|date',
            'is_active' => 'sometimes|boolean',
        ]);

        $obligation = $policy->obligations()->create($data);

        return response()->json(['data' => $obligation->load(['owner', 'control'])], JsonResponse::HTTP_CREATED);
    }

    public function attest(StorePolicyAttestationRequest $request, PolicyObligation $obligation, PolicyCompliance $compliance): JsonResponse
    {
        $data = $request->validated();

        $attestation = $compliance->attest(
            $obligation,
            $request->user(),
            PolicyAttestationOutcome::from($data['outcome']),
            $data['statement'],
            $data['evidence_reference'] ?? null,
            isset($data['policy_exception_id']) ? PolicyException::findOrFail($data['policy_exception_id']) : null,
            evidenceAttachmentIds: $data['evidence_attachment_ids'] ?? [],
        );

        return response()->json([
            'data' => $attestation,
            'obligation' => $obligation->refresh(),
        ], JsonResponse::HTTP_CREATED);
    }
}
