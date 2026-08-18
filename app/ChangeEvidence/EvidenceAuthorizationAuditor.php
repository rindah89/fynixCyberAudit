<?php

namespace App\ChangeEvidence;

use App\Models\EvidenceAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EvidenceAuthorizationAuditor
{
    public function denied(string $reason, array $context): void
    {
        ksort($context);
        $this->append(
            isset($context['authorization_id']) ? (int) $context['authorization_id'] : null,
            'denied', null,
            hash('sha256', json_encode($context, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            $reason,
            is_int($context['company_id'] ?? null) ? $context['company_id'] : null,
            is_string($context['profile'] ?? null) ? $context['profile'] : null,
        );
    }

    public function append(?int $authorizationId, string $action, ?int $actor, string $detailDigest, ?string $reason = null, ?int $companyId = null, ?string $profile = null): void
    {
        DB::transaction(function () use ($authorizationId, $action, $actor, $detailDigest, $reason, $companyId, $profile): void {
            if ($authorizationId !== null) {
                $authorization = EvidenceAuthorization::lockForUpdate()->findOrFail($authorizationId);
                $companyId = (int) $authorization->company_id;
                $profile = $authorization->profile;
            }
            $previous = $authorizationId === null ? null : DB::table('evidence_authorization_audit')
                ->where('authorization_id', $authorizationId)->orderByDesc('id')->lockForUpdate()->value('event_digest');
            $occurredAt = now()->utc();
            $nonce = (string) Str::uuid();
            $payload = [
                'action' => $action, 'actor_user_id' => $actor, 'authorization_id' => $authorizationId,
                'company_id' => $companyId, 'detail_digest' => $detailDigest, 'event_nonce' => $nonce,
                'occurred_at' => $occurredAt->toIso8601String(), 'previous_digest' => $previous, 'profile' => $profile,
            ];
            if ($reason !== null) {
                $payload['reason_code'] = $reason;
            }
            ksort($payload);
            $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            DB::table('evidence_authorization_audit')->insert([
                'authorization_id' => $authorizationId, 'company_id' => $companyId, 'profile' => $profile,
                'actor_user_id' => $actor, 'action' => $action, 'reason_code' => $reason,
                'previous_digest' => $previous, 'event_nonce' => $nonce, 'occurred_at' => $occurredAt,
                'canonical_payload' => $canonical, 'event_digest' => hash('sha256', $canonical), 'created_at' => $occurredAt,
            ]);
        }, 5);
    }
}
