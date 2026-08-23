<?php

namespace App\Http\Controllers\API;

use App\Enums\PolicyAttestationOutcome;
use App\Enums\PolicyObligationFrequency;
use App\Http\Controllers\Controller;
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

    public function attest(Request $request, PolicyObligation $obligation, PolicyCompliance $compliance): JsonResponse
    {
        $this->authorize('attest', $obligation);

        $data = $request->validate([
            'outcome' => ['required', Rule::enum(PolicyAttestationOutcome::class)],
            'statement' => 'required|string|max:10000',
            'evidence_reference' => 'nullable|string|max:255',
            'policy_exception_id' => [
                'nullable',
                Rule::exists('policy_exceptions', 'id')->where('policy_id', $obligation->policy_id),
            ],
        ]);

        $attestation = $compliance->attest(
            $obligation,
            $request->user(),
            PolicyAttestationOutcome::from($data['outcome']),
            $data['statement'],
            $data['evidence_reference'] ?? null,
            isset($data['policy_exception_id']) ? PolicyException::findOrFail($data['policy_exception_id']) : null,
        );

        return response()->json([
            'data' => $attestation,
            'obligation' => $obligation->refresh(),
        ], JsonResponse::HTTP_CREATED);
    }
}
