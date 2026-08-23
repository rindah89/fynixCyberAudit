<?php

namespace App\Http\Controllers\API;

use App\Enums\PolicyAttestationOutcome;
use App\Enums\PolicyObligationFrequency;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePolicyAttestationRequest;
use App\Models\Policy;
use App\Models\PolicyException;
use App\Models\PolicyObligation;
use App\PolicyCompliance\PolicyCompliance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PolicyComplianceController extends Controller
{
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
