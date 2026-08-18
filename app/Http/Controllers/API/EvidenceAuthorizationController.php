<?php

namespace App\Http\Controllers\API;

use App\ChangeEvidence\EvidenceAuthorizationAuditor;
use App\ChangeEvidence\EvidencePolicyRegistry as Registry;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClaimEvidenceAuthorizationRequest;
use App\Http\Requests\ConsumeEvidenceAuthorizationRequest;
use App\Http\Requests\StoreEvidenceAuthorizationRequest;
use App\Models\EvidenceAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EvidenceAuthorizationController extends Controller
{
    private const ITSM_URL = 'https://itsm.fynixhq.com/api/v1';

    public function store(StoreEvidenceAuthorizationRequest $request): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 65536, 413);
        $body = $request->validated();
        $key = $this->machine($request, $body['profile'], $body['company_id'] ?? null);
        $binding = $this->authority($body);
        $this->liveItsm($body);

        try {
            return DB::transaction(function () use ($body, $key, $binding): JsonResponse {
                $existing = EvidenceAuthorization::where(['company_id' => $body['company_id'], 'profile' => $body['profile'], 'request_id' => $body['request_id']])->lockForUpdate()->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->request_digest, $body['request_digest']), 409);

                    return response()->json($this->output($existing));
                }
                $authorization = EvidenceAuthorization::create([
                    'profile' => $body['profile'], 'company_id' => $body['company_id'],
                    'suite_tenant_id' => $body['suite_tenant_id'], 'customer_id' => $body['customer_id'],
                    'requester_key_id' => $key->key_id, 'request_id' => $body['request_id'],
                    'authority_binding_version' => (int) $binding->version,
                    'operation_id' => $body['operation_id'], 'request_digest' => $body['request_digest'],
                    'request_json' => $body, 'retention_until' => now()->addYears((int) config('change_evidence.retention_years', 7)),
                ]);
                $this->audit($authorization, 'requested', null, null);

                return response()->json($this->output($authorization), 201);
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKey($exception)) {
                throw $exception;
            }
            abort(409, 'Request or operation is already bound.');
        }
    }

    public function show(Request $request, EvidenceAuthorization $authorization): JsonResponse
    {
        $key = $this->machine($request, $authorization->profile, (int) $authorization->company_id, $authorization);
        $this->originKey($authorization, $key);
        $this->current($authorization);
        $this->liveItsm($authorization->request_json);

        return response()->json($this->output($authorization));
    }

    public function accept(Request $request, EvidenceAuthorization $authorization): JsonResponse
    {
        return $this->decide($request, $authorization, 'accepted');
    }

    public function reject(Request $request, EvidenceAuthorization $authorization): JsonResponse
    {
        return $this->decide($request, $authorization, 'rejected');
    }

    public function revoke(Request $request, EvidenceAuthorization $authorization): JsonResponse
    {
        $this->reviewer($request, $authorization, 'can_revoke');

        return $this->guardedTransaction($authorization, function () use ($request, $authorization): JsonResponse {
            $locked = EvidenceAuthorization::lockForUpdate()->findOrFail($authorization->id);
            $this->current($locked);
            abort_unless($locked->status === 'accepted' && ! $locked->consumed_at && $locked->reviewed_by !== $request->user()->id, 409);
            $locked->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $request->user()->id]);
            $this->audit($locked, 'revoked', $request->user()->id, null);

            return response()->json($this->output($locked));
        }, 3);
    }

    public function claim(ClaimEvidenceAuthorizationRequest $request, EvidenceAuthorization $authorization): JsonResponse
    {
        $key = $this->machine($request, $authorization->profile, (int) $authorization->company_id, $authorization);
        $this->originKey($authorization, $key);
        $body = $request->validated();

        try {
            return $this->guardedTransaction($authorization, function () use ($body, $authorization): JsonResponse {
                $locked = EvidenceAuthorization::lockForUpdate()->findOrFail($authorization->id);
                $this->current($locked);
                abort_unless($locked->status === 'accepted' && ! $locked->consumed_at && hash_equals($locked->request_digest, (string) $body['request_digest']), 409);
                abort_if(DB::table('evidence_authorization_claims')->where('authorization_id', $locked->id)->exists(), 409, 'A claim has already been issued.');
                $token = bin2hex(random_bytes(32));
                $issued = now();
                DB::table('evidence_authorization_claims')->insert(['authorization_id' => $locked->id, 'nonce' => $body['nonce'], 'token_digest' => hash('sha256', $token), 'issued_at' => $issued, 'expires_at' => $issued->copy()->addSeconds($body['ttl_seconds']), 'created_at' => $issued, 'updated_at' => $issued]);
                $this->audit($locked, 'claimed', null, hash('sha256', $body['nonce']));

                return response()->json(['authorization_id' => $locked->id, 'purpose' => 'deploy', 'nonce' => $body['nonce'], 'issued_at' => $issued->toIso8601String(), 'expires_at' => $issued->copy()->addSeconds($body['ttl_seconds'])->toIso8601String(), 'claim_token' => $token], 201);
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKey($exception)) {
                throw $exception;
            }
            abort(409, 'A claim has already been issued.');
        }
    }

    public function consume(ConsumeEvidenceAuthorizationRequest $request, EvidenceAuthorization $authorization): JsonResponse
    {
        $key = $this->machine($request, $authorization->profile, (int) $authorization->company_id, $authorization);
        $this->originKey($authorization, $key);
        $body = $request->validated();

        return $this->guardedTransaction($authorization, function () use ($body, $authorization): JsonResponse {
            $locked = EvidenceAuthorization::lockForUpdate()->findOrFail($authorization->id);
            $this->current($locked);
            $claim = DB::table('evidence_authorization_claims')->where('authorization_id', $locked->id)->lockForUpdate()->first();
            if ($locked->consumed_at) {
                abort_unless($claim && hash_equals($claim->token_digest, hash('sha256', (string) ($body['claim_token'] ?? ''))) && hash_equals($locked->operation_id, (string) $body['operation_id']) && hash_equals($locked->request_digest, (string) $body['request_digest']), 409);

                return response()->json([...$locked->receipt_json, 'receipt_digest' => $locked->receipt_digest, 'signature' => $locked->signature]);
            }
            abort_unless($locked->status === 'accepted' && $body['purpose'] === 'deploy' && hash_equals($locked->operation_id, (string) $body['operation_id']) && hash_equals($locked->request_digest, (string) $body['request_digest']), 409);
            abort_unless($claim && ! $claim->consumed_at && ! $claim->revoked_at && CarbonImmutable::parse($claim->expires_at)->isFuture() && is_string($body['claim_token'] ?? null) && hash_equals($claim->token_digest, hash('sha256', $body['claim_token'])), 409, 'Claim is absent, expired, consumed, revoked, or invalid.');
            $binding = $this->authority($locked->request_json, (int) $locked->authority_binding_version);
            $this->liveItsm($locked->request_json);
            [$keyId, $secret] = $this->signingIdentity();
            $now = now();
            $receipt = ['version' => 'fynix-cyberaudit-evidence-authorization/v3', 'origin' => 'fynix-cyberaudit', ...$locked->request_json, 'accepted' => true, 'requested_at' => $locked->created_at->toIso8601String(), 'reviewed_at' => $locked->reviewed_at->toIso8601String(), 'observed_at' => $now->toIso8601String(), 'issued_at' => $now->toIso8601String(), 'expires_at' => $locked->expires_at->toIso8601String(), 'consumed_at' => $now->toIso8601String(), 'reviewer_id' => hash('sha256', 'user:'.$locked->reviewed_by), 'authority' => 'executive-hq', 'authority_binding_version' => (int) $binding->version, 'authority_binding_verified_at' => CarbonImmutable::parse($binding->verified_at)->utc()->toIso8601String(), 'authority_binding_digest' => $this->authorityDigest($binding), 'claim_nonce' => $claim->nonce, 'key_id' => $keyId];
            $digest = hash('sha256', $this->canonical($receipt));
            $signature = rtrim(strtr(base64_encode(sodium_crypto_sign_detached(hex2bin($digest), $secret)), '+/', '-_'), '=');
            DB::table('evidence_authorization_claims')->where('id', $claim->id)->update(['consumed_at' => $now, 'updated_at' => $now]);
            $locked->update(['consumed_at' => $now, 'receipt_json' => $receipt, 'receipt_digest' => $digest, 'signature' => $signature, 'key_id' => $keyId]);
            $this->audit($locked, 'consumed', null, $digest);

            return response()->json([...$receipt, 'receipt_digest' => $digest, 'signature' => $signature]);
        }, 3);
    }

    private function decide(Request $request, EvidenceAuthorization $authorization, string $status): JsonResponse
    {
        $this->reviewer($request, $authorization, 'can_review');

        return $this->guardedTransaction($authorization, function () use ($request, $authorization, $status): JsonResponse {
            $locked = EvidenceAuthorization::lockForUpdate()->findOrFail($authorization->id);
            $this->current($locked);
            abort_unless($locked->status === 'pending', 409);
            $ttl = (int) config('change_evidence.ttl_seconds', 600);
            abort_unless($ttl >= 60 && $ttl <= 600, 503);
            $locked->update(['status' => $status, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'expires_at' => now()->addSeconds($ttl)]);
            $this->audit($locked, $status, $request->user()->id, null);

            return response()->json($this->output($locked));
        }, 3);
    }

    private function machine(Request $request, string $profile, mixed $companyId, ?EvidenceAuthorization $authorization = null): object
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) >= 32 && strlen($token) <= 4096, 401);
        $key = DB::table('evidence_requester_keys')->where(['token_digest' => hash('sha256', $token), 'company_id' => $companyId, 'profile' => $profile])->first();
        $current = $key && $key->active && (! $key->expires_at || CarbonImmutable::parse($key->expires_at)->isFuture());
        if (! $current && $authorization && $key && hash_equals($authorization->requester_key_id, $key->key_id)) {
            $this->persistCredentialInvalidation($authorization->id);
        }
        if (! $current) {
            $this->deny('credential_denied', ['authorization_id' => $authorization?->id, 'company_id' => $companyId, 'profile' => $profile], 403, 'Credential lacks this profile entitlement.');
        }

        return $key;
    }

    private function persistCredentialInvalidation(int $authorizationId): void
    {
        DB::transaction(function () use ($authorizationId): void {
            $authorization = EvidenceAuthorization::lockForUpdate()->findOrFail($authorizationId);
            if (! $authorization->consumed_at && ! $authorization->revoked_at) {
                $authorization->update(['status' => 'revoked', 'revoked_at' => now()]);
                DB::table('evidence_authorization_claims')->where('authorization_id', $authorization->id)->whereNull('consumed_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
                $this->audit($authorization, 'credential_revoked', null, null);
            }
        }, 3);
    }

    private function reviewer(Request $request, EvidenceAuthorization $authorization, string $capability): void
    {
        $permission = $capability === 'can_review' ? 'review change evidence' : 'revoke change evidence';
        if (! ($request->user() && $request->user()->can($permission) && DB::table('evidence_profile_reviewers')->where(['user_id' => $request->user()->id, 'company_id' => $authorization->company_id, 'profile' => $authorization->profile, 'active' => 1, $capability => 1])->exists())) {
            $this->deny('reviewer_entitlement_denied', ['authorization_id' => $authorization->id, 'company_id' => (int) $authorization->company_id, 'profile' => $authorization->profile], 403);
        }
    }

    private function authority(array $request, ?int $expectedVersion = null): object
    {
        $binding = DB::table('executive_authority_bindings')->where(['company_id' => $request['company_id'], 'authority' => 'executive-hq', 'active' => 1])->first();
        if (! ($binding && hash_equals($binding->suite_tenant_id, $request['suite_tenant_id']) && hash_equals($binding->customer_id, $request['customer_id']) && ($expectedVersion === null || (int) $binding->version === $expectedVersion))) {
            $this->deny('tenant_authority_denied', ['company_id' => $request['company_id'], 'profile' => $request['profile'] ?? null], 403, 'Executive authority is not current.');
        }

        return $binding;
    }

    private function current(EvidenceAuthorization $authorization): void
    {
        $this->authority($authorization->request_json, (int) $authorization->authority_binding_version);
        abort_if($authorization->revoked_at || ($authorization->expires_at && $authorization->expires_at->isPast()), 410);
    }

    private function guardedTransaction(EvidenceAuthorization $authorization, callable $callback): JsonResponse
    {
        try {
            return DB::transaction($callback, 3);
        } catch (HttpException $exception) {
            if (in_array($exception->getStatusCode(), [403, 503], true)) {
                app(EvidenceAuthorizationAuditor::class)->denied('transaction_authority_denied', [
                    'authorization_id' => $authorization->id,
                    'company_id' => (int) $authorization->company_id,
                    'profile' => $authorization->profile,
                ]);
            }
            if ($exception->getStatusCode() === 403) {
                DB::transaction(function () use ($authorization): void {
                    $locked = EvidenceAuthorization::lockForUpdate()->findOrFail($authorization->id);
                    if (! $locked->consumed_at && ! $locked->revoked_at) {
                        $locked->update(['status' => 'revoked', 'revoked_at' => now()]);
                        $this->audit($locked, 'authority_revoked', null, null);
                    }
                }, 3);
            }
            throw $exception;
        }
    }

    private function originKey(EvidenceAuthorization $authorization, object $key): void
    {
        if (! hash_equals($authorization->requester_key_id, $key->key_id)) {
            $this->deny('credential_origin_denied', ['authorization_id' => $authorization->id, 'company_id' => (int) $authorization->company_id, 'profile' => $authorization->profile], 403, 'Authorization belongs to another requester key.');
        }
    }

    private function liveItsm(array $request): void
    {
        $url = self::ITSM_URL.'/change-authorizations/'.$request['itsm_authorization_id'];
        $token = $this->ownerOnlyFile(config('change_evidence.itsm_api_key_file'), 4096);
        try {
            $response = Http::withoutRedirecting()->acceptJson()->withToken($token)->timeout(10)->get($url);
        } catch (\Throwable) {
            $this->deny('itsm_unavailable', ['company_id' => $request['company_id'], 'profile' => $request['profile']], 503, 'Live ITSM authority unavailable.');
        }
        if (! ($response->successful() && $response->effectiveUri()->__toString() === $url && strlen($response->body()) <= 65536)) {
            $this->deny('itsm_unavailable', ['company_id' => $request['company_id'], 'profile' => $request['profile']], 503, 'Live ITSM authority unavailable.');
        }
        $outer = $response->json();
        if (! (is_array($outer) && array_keys($outer) === ['data'] && is_array($outer['data']))) {
            $this->deny('itsm_schema_denied', ['company_id' => $request['company_id'], 'profile' => $request['profile']], 503);
        }
        $row = $outer['data'];
        $immutable = $request;
        foreach (['purpose', 'operation_id', 'policy_version', 'itsm_change_id', 'itsm_authorization_id', 'itsm_approval_revision', 'itsm_binding_digest', 'request_digest'] as $field) {
            unset($immutable[$field]);
        }
        $immutable['contract_version'] = 'fynix-change-authorization/v2';
        $bindingDigest = hash('sha256', $this->canonical($immutable));
        $expected = [...$immutable, 'binding_digest' => $bindingDigest, 'id' => $request['itsm_authorization_id'], 'change_id' => $request['itsm_change_id'], 'policy_version' => $request['policy_version'], 'approval_revision' => $request['itsm_approval_revision'], 'revoked' => false];
        $fields = [...array_keys($expected), 'created_at', 'expires_at'];
        if (count($row) !== count($fields) || array_diff(array_keys($row), $fields)) {
            $this->deny('itsm_schema_denied', ['company_id' => $request['company_id'], 'profile' => $request['profile']], 503);
        }
        foreach ($expected as $field => $value) {
            if (($row[$field] ?? null) !== $value) {
                $this->deny('itsm_binding_denied', ['company_id' => $request['company_id'], 'profile' => $request['profile']], 403, 'Live ITSM authorization differs from the requested evidence.');
            }
        }
        if (! (hash_equals($bindingDigest, $request['itsm_binding_digest']) && hash_equals($bindingDigest, $row['binding_digest']) && CarbonImmutable::parse($row['created_at'])->lessThanOrEqualTo(now()->addMinutes(5)) && CarbonImmutable::parse($row['expires_at'])->isFuture())) {
            $this->deny('itsm_current_denied', ['company_id' => $request['company_id'], 'profile' => $request['profile']], 403, 'Live ITSM authorization is not current.');
        }
    }

    private function signingIdentity(): array
    {
        $secret = base64_decode($this->ownerOnlyFile(config('change_evidence.signing_key_file'), 4096), true);
        abort_unless(is_string($secret) && strlen($secret) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 503);
        $id = config('change_evidence.signing_key_id');
        abort_unless(is_string($id) && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $id), 503);
        $keys = json_decode($this->ownerOnlyFile(config('change_evidence.signing_public_keys_file'), 4096), true);
        abort_unless(is_array($keys) && count($keys) >= 1 && count($keys) <= 2 && isset($keys[$id]), 503);
        foreach ($keys as $keyId => $encoded) {
            $public = is_string($encoded) ? base64_decode($encoded, true) : false;
            abort_unless(is_string($keyId) && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $keyId) && is_string($public) && strlen($public) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 503);
        }
        abort_unless(hash_equals(base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)), $keys[$id]), 503);

        return [$id, $secret];
    }

    private function ownerOnlyFile(mixed $path, int $limit): string
    {
        abort_unless(is_string($path) && str_starts_with($path, '/') && ! is_link($path) && is_file($path) && (fileperms($path) & 077) === 0 && filesize($path) <= $limit, 503);

        return trim((string) file_get_contents($path));
    }

    private function authorityDigest(object $binding): string
    {
        return hash('sha256', $this->canonical(['authority' => 'executive-hq', 'company_id' => (int) $binding->company_id, 'customer_id' => $binding->customer_id, 'suite_tenant_id' => $binding->suite_tenant_id, 'verified_at' => CarbonImmutable::parse($binding->verified_at)->utc()->toIso8601String(), 'version' => (int) $binding->version]));
    }

    private function canonical(array $value): string
    {
        ksort($value);

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || in_array($exception->errorInfo[1] ?? null, [1062, 1555, 2067], true);
    }

    private function deny(string $reason, array $context, int $status, string $message = ''): never
    {
        app(EvidenceAuthorizationAuditor::class)->denied($reason, $context);
        abort($status, $message);
    }

    private function audit(EvidenceAuthorization $authorization, string $action, ?int $actor, ?string $detail): void
    {
        $previous = DB::table('evidence_authorization_audit')->where('authorization_id', $authorization->id)->orderByDesc('id')->value('event_digest');
        $occurredAt = now()->utc();
        $nonce = (string) Str::uuid();
        $payload = ['action' => $action, 'actor_user_id' => $actor, 'authorization_id' => $authorization->id, 'company_id' => (int) $authorization->company_id, 'detail_digest' => $detail ?? $authorization->request_digest, 'event_nonce' => $nonce, 'occurred_at' => $occurredAt->toIso8601String(), 'previous_digest' => $previous, 'profile' => $authorization->profile];
        $digest = hash('sha256', $this->canonical($payload));
        DB::table('evidence_authorization_audit')->insert(['authorization_id' => $authorization->id, 'company_id' => $authorization->company_id, 'profile' => $authorization->profile, 'actor_user_id' => $actor, 'action' => $action, 'previous_digest' => $previous, 'event_nonce' => $nonce, 'occurred_at' => $occurredAt, 'canonical_payload' => $this->canonical($payload), 'event_digest' => $digest, 'created_at' => $occurredAt]);
    }

    /** @return array<string, int|string|null> */
    private function output(EvidenceAuthorization $authorization): array
    {
        return ['id' => $authorization->id, 'contract_version' => Registry::CONTRACT, 'profile' => $authorization->profile, 'request_id' => $authorization->request_id, 'request_digest' => $authorization->request_digest, 'status' => $authorization->status, 'expires_at' => $authorization->expires_at?->toIso8601String(), 'revoked_at' => $authorization->revoked_at?->toIso8601String(), 'consumed_at' => $authorization->consumed_at?->toIso8601String(), 'retention_until' => $authorization->retention_until->toIso8601String()];
    }
}
