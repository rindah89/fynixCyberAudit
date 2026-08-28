<?php

namespace App\Suite;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CyberAuditPrivacyRightsController
{
    public function __invoke(Request $request, CyberAuditPrivacyRightsService $rights, DataGovernanceControlService $controls, CyberAuditPrivacyExportService $exports): JsonResponse
    {
        $validated = $request->validate([
            'right' => ['required', 'in:correction,restriction,objection,deletion'],
            'identity_verification_ref' => ['required', 'string', 'max:512', 'regex:/^(urn:fynix:|evidence:\/\/)[A-Za-z0-9._:\/-]+$/'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);
        $tenantId = (string) config('data_governance.publisher.tenant_id', '');
        abort_if($tenantId === '', 503, 'CyberAudit privacy oversight binding is unavailable.');
        $user = $request->user();
        $subjectRef = $exports->subjectRef($user);
        $result = DB::transaction(function () use ($controls, $rights, $validated, $tenantId, $subjectRef, $user): array {
            $privacyRequest = $controls->openPrivacyRequest([
                'tenant_id' => $tenantId, 'source' => 'cyberaudit', 'subject_ref' => $subjectRef,
                'right' => $validated['right'], 'lawful_basis' => 'verified_data_subject_request', 'requested_at' => now(),
            ]);
            $result = $rights->fulfill($user, $validated['right'], $validated);
            $evidence = ['subject_ref' => $subjectRef, 'identity_verification_ref' => $validated['identity_verification_ref'], ...$result];
            $digest = hash('sha256', json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $controls->closePrivacyRequest($privacyRequest, "urn:fynix:cyberaudit:privacy-right:{$validated['right']}:{$subjectRef}", $digest);

            return [...$evidence, 'evidence_sha256' => $digest];
        });

        return response()->json($result)->header('Cache-Control', 'no-store');
    }
}
