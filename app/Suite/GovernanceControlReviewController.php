<?php

namespace App\Suite;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GovernanceControlReviewController
{
    public function store(Request $request, GovernanceControlReviewService $service): JsonResponse
    {
        abort_unless($request->user()?->can('Review Governance Controls'), 403);
        $validator = Validator::make($request->all(), [
            'resource_type' => ['required', 'in:processor,recovery_evidence,disposition_receipt,privacy_completion,retention_run'],
            'resource_id' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'in:approved,rejected'],
            'review_evidence_ref' => ['required', 'regex:/^(urn:fynix:|evidence:\/\/)[A-Za-z0-9._:\/-]+$/', 'max:2048'],
            'review_evidence_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        if ($validator->fails()) {
            return response()->json(['outcome' => 'invalid review', 'errors' => $validator->errors()], 422);
        }

        try {
            $review = $service->review($validator->validated(), $request->user());
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['outcome' => 'invalid review', 'message' => $exception->getMessage()], 409);
        }

        return response()->json(['outcome' => $review->decision, 'review_id' => $review->id, 'review_digest' => $review->review_digest], 201);
    }

    public function certifyProcessorRegister(Request $request, GovernanceControlReviewService $service): JsonResponse
    {
        abort_unless($request->user()?->can('Review Governance Controls'), 403);
        $validator = Validator::make($request->all(), [
            'tenant_id' => ['required', 'string', 'max:128'], 'source' => ['required', 'string', 'max:32'],
            'expected_processor_count' => ['required', 'integer', 'min:1'],
            'valid_until' => ['required', 'date', 'after:today'],
            'review_evidence_ref' => ['required', 'regex:/^(urn:fynix:|evidence:\/\/)[A-Za-z0-9._:\/-]+$/', 'max:2048'],
            'review_evidence_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        if ($validator->fails()) {
            return response()->json(['outcome' => 'invalid review', 'errors' => $validator->errors()], 422);
        }
        try {
            $certification = $service->certifyProcessorRegister($validator->validated(), $request->user());
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['outcome' => 'invalid review', 'message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'outcome' => 'approved', 'certification_id' => $certification->id,
            'processor_count' => $certification->processor_count, 'inventory_digest' => $certification->inventory_digest,
        ], 201);
    }
}
