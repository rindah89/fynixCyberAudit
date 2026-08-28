<?php

namespace App\Suite;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CyberAuditPrivacyExportController
{
    public function __invoke(Request $request, CyberAuditPrivacyExportService $exports, DataGovernanceControlService $controls): JsonResponse
    {
        $validated = $request->validate([
            'identity_verification_ref' => ['required', 'string', 'max:512', 'regex:/^(urn:fynix:|evidence:\/\/)[A-Za-z0-9._:\/-]+$/'],
        ]);
        $user = $request->user();
        $export = $exports->export($user, $validated['identity_verification_ref']);
        $tenantId = (string) config('data_governance.publisher.tenant_id', '');
        if ($tenantId === '') {
            return response()->json(['message' => 'CyberAudit privacy oversight binding is unavailable.'], 503);
        }
        $privacyRequest = $controls->openPrivacyRequest([
            'tenant_id' => $tenantId, 'source' => 'cyberaudit',
            'subject_ref' => $export['subject_ref'], 'right' => 'access',
            'lawful_basis' => 'data_subject_access', 'requested_at' => now(),
        ]);
        $controls->closePrivacyRequest(
            $privacyRequest,
            'urn:fynix:cyberaudit:privacy-export:'.$export['subject_ref'],
            $export['evidence_sha256'],
        );

        return response()->json($export)->header('Cache-Control', 'no-store');
    }
}
