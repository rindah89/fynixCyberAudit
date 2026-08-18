<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SupportChangeEvidenceAcceptance;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExecutiveAuthorityBindingController extends Controller
{
    private const FIELDS = ['contract_version', 'event_id', 'nonce', 'company_id', 'suite_tenant_id', 'customer_id', 'version', 'active', 'issued_at', 'expires_at'];

    public function store(Request $request): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 16384, 413);
        abort_unless($request->header('X-Fynix-Origin') === config('change_evidence.executive_origin'), 401);
        $body = $request->all();
        abort_unless(count($body) === count(self::FIELDS) && ! array_diff(array_keys($body), self::FIELDS), 422);
        abort_unless(($body['contract_version'] ?? null) === 'fynix-executive-authority-binding/v1', 422);
        abort_unless(is_int($body['company_id'] ?? null) && $body['company_id'] > 0 && is_int($body['version'] ?? null) && $body['version'] > 0 && is_bool($body['active'] ?? null), 422);
        foreach (['event_id', 'nonce', 'suite_tenant_id', 'customer_id'] as $field) {
            abort_unless(is_string($body[$field] ?? null) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $body[$field]), 422);
        }
        $issued = $this->time($body['issued_at'] ?? null);
        $expires = $this->time($body['expires_at'] ?? null);
        $now = CarbonImmutable::now('UTC');
        abort_unless($issued->lessThanOrEqualTo($now->addSeconds(60)) && $expires->greaterThan($now) && $expires->lessThanOrEqualTo($issued->addSeconds(300)), 410);
        $digest = hash('sha256', $this->canonical($body));
        $keyId = (string) $request->header('X-Fynix-Key-Id', '');
        $signature = $this->decode((string) $request->header('X-Fynix-Signature', ''));
        $keys = $this->publicKeys();
        abort_unless(isset($keys[$keyId]) && sodium_crypto_sign_verify_detached($signature, hex2bin($digest), $keys[$keyId]), 401);

        try {
            return DB::transaction(function () use ($body, $digest, $keyId): JsonResponse {
                $event = DB::table('executive_authority_binding_events')->where('event_id', $body['event_id'])->lockForUpdate()->first();
                if ($event) {
                    abort_unless(hash_equals($event->event_digest, $digest), 409);

                    return response()->json(['outcome' => $event->outcome, 'event_digest' => $digest]);
                }
                $current = DB::table('executive_authority_bindings')->where('company_id', $body['company_id'])->lockForUpdate()->first();
                abort_if($current && (int) $body['version'] < (int) $current->version, 409, 'Authority version is stale.');
                if ($current && (int) $body['version'] === (int) $current->version) {
                    abort_unless($this->sameBinding($current, $body), 409, 'Authority version is already bound differently.');
                    $outcome = 'duplicate';
                } else {
                    DB::table('executive_authority_bindings')->updateOrInsert(['company_id' => $body['company_id']], ['suite_tenant_id' => $body['suite_tenant_id'], 'customer_id' => $body['customer_id'], 'authority' => 'executive-hq', 'version' => $body['version'], 'active' => $body['active'], 'verified_at' => CarbonImmutable::now('UTC'), 'created_at' => $current?->created_at ?? now(), 'updated_at' => now()]);
                    $outcome = $body['active'] ? 'applied' : 'deactivated';
                    if ($current && (int) $body['version'] > (int) $current->version) {
                        $this->invalidate($body['company_id']);
                    }
                }
                DB::table('executive_authority_binding_events')->insert(['event_id' => $body['event_id'], 'nonce' => $body['nonce'], 'company_id' => $body['company_id'], 'version' => $body['version'], 'event_digest' => $digest, 'key_id' => $keyId, 'outcome' => $outcome, 'received_at' => now()]);

                return response()->json(['outcome' => $outcome, 'event_digest' => $digest], 202);
            }, 3);
        } catch (QueryException $e) {
            if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }
            abort(409, 'Authority event or nonce replay conflict.');
        }
    }

    private function invalidate(int $companyId): void
    {
        $rows = SupportChangeEvidenceAcceptance::where('company_id', $companyId)->whereNull('consumed_at')->whereIn('status', ['pending', 'accepted'])->lockForUpdate()->get();
        foreach ($rows as $row) {
            $row->update(['status' => 'revoked', 'revoked_at' => $row->revoked_at ?? now()]);
            DB::table('support_change_evidence_audit')->insert(['acceptance_id' => $row->id, 'company_id' => $companyId, 'actor_user_id' => null, 'action' => 'authority_revoked', 'reason_code' => 'executive_binding_changed', 'details_digest' => hash('sha256', 'executive_binding_changed'.$row->request_digest), 'created_at' => now()]);
        }
        $authorizations = DB::table('evidence_authorizations')->where('company_id', $companyId)->whereNull('consumed_at')->whereIn('status', ['pending', 'accepted'])->lockForUpdate()->get();
        foreach ($authorizations as $authorization) {
            DB::table('evidence_authorizations')->where('id', $authorization->id)->update(['status' => 'revoked', 'revoked_at' => $authorization->revoked_at ?? now(), 'updated_at' => now()]);
            DB::table('evidence_authorization_claims')->where('authorization_id', $authorization->id)->whereNull('consumed_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            $previous = DB::table('evidence_authorization_audit')->where('authorization_id', $authorization->id)->orderByDesc('id')->value('event_digest');
            $occurredAt = now()->utc();
            $nonce = (string) Str::uuid();
            $payload = ['action' => 'authority_revoked', 'actor_user_id' => null, 'authorization_id' => $authorization->id, 'company_id' => (int) $authorization->company_id, 'detail_digest' => hash('sha256', 'executive_binding_changed'.$authorization->request_digest), 'event_nonce' => $nonce, 'occurred_at' => $occurredAt->toIso8601String(), 'previous_digest' => $previous, 'profile' => $authorization->profile];
            ksort($payload);
            $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            DB::table('evidence_authorization_audit')->insert(['authorization_id' => $authorization->id, 'company_id' => $authorization->company_id, 'profile' => $authorization->profile, 'actor_user_id' => null, 'action' => 'authority_revoked', 'previous_digest' => $previous, 'event_nonce' => $nonce, 'occurred_at' => $occurredAt, 'canonical_payload' => $canonical, 'event_digest' => hash('sha256', $canonical), 'created_at' => $occurredAt]);
        }
    }

    private function sameBinding(object $current, array $body): bool
    {
        return $current->suite_tenant_id === $body['suite_tenant_id'] && $current->customer_id === $body['customer_id'] && (bool) $current->active === $body['active'];
    }

    private function publicKeys(): array
    {
        $path = config('change_evidence.executive_public_keys_file');
        abort_unless(is_string($path) && str_starts_with($path, '/') && is_file($path) && ! is_link($path) && (fileperms($path) & 077) === 0 && filesize($path) <= 4096, 503);
        $set = json_decode((string) file_get_contents($path), true);
        abort_unless(is_array($set) && count($set) >= 1 && count($set) <= 2, 503);
        $keys = [];
        foreach ($set as $id => $encoded) {
            $key = is_string($encoded) ? base64_decode($encoded, true) : false;
            abort_unless(is_string($id) && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $id) && is_string($key) && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 503);
            $keys[$id] = $key;
        }

        return $keys;
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        abort_unless(is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_SIGN_BYTES, 401);

        return $decoded;
    }

    private function time(mixed $value): CarbonImmutable
    {
        try {
            $time = CarbonImmutable::parse((string) $value)->utc();
        } catch (\Throwable) {
            abort(422, 'Invalid authority timestamp.');
        }
        abort_unless(is_string($value) && preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})$/', $value), 422);

        return $time;
    }

    private function canonical(array $value): string
    {
        ksort($value);

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
