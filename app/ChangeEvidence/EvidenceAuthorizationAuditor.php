<?php

namespace App\ChangeEvidence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EvidenceAuthorizationAuditor
{
    public function denied(string $reason, array $context): void
    {
        $companyId = is_int($context['company_id'] ?? null) ? $context['company_id'] : null;
        $profile = is_string($context['profile'] ?? null) ? $context['profile'] : null;
        $occurredAt = now()->utc();
        $nonce = (string) Str::uuid();
        ksort($context);
        $payload = [
            'action' => 'denied', 'actor_user_id' => null, 'authorization_id' => $context['authorization_id'] ?? null,
            'company_id' => $companyId, 'detail_digest' => hash('sha256', json_encode($context, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'event_nonce' => $nonce, 'occurred_at' => $occurredAt->toIso8601String(), 'previous_digest' => null,
            'profile' => $profile, 'reason_code' => $reason,
        ];
        ksort($payload);
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        DB::table('evidence_authorization_audit')->insert([
            'authorization_id' => $payload['authorization_id'], 'company_id' => $companyId, 'profile' => $profile,
            'action' => 'denied', 'reason_code' => $reason, 'event_nonce' => $nonce, 'occurred_at' => $occurredAt,
            'canonical_payload' => $canonical, 'event_digest' => hash('sha256', $canonical), 'created_at' => $occurredAt,
        ]);
    }
}
