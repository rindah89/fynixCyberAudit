<?php

namespace App\Http\Controllers\API;

use App\ChangeEvidence\EvidencePolicyRegistry as Registry;
use App\Http\Controllers\Controller;
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
    public function store(Request $request): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 65536, 413);
        $body = $request->all();
        abort_unless(count($body) === count(Registry::REQUEST_FIELDS) && ! array_diff(array_keys($body), Registry::REQUEST_FIELDS), 422, 'Closed request schema required.');
        $policy = Registry::resolve((string) ($body['profile'] ?? ''));
        abort_unless($policy !== null, 422, 'Unknown evidence profile.');
        $key = $this->machine($request, $body['profile'], $body['company_id'] ?? null);
        $this->validateRequest($body, $policy);
        $this->authority($body);
        $this->liveItsm($body);

        try {
            return DB::transaction(function () use ($body, $key): JsonResponse {
                $existing = EvidenceAuthorization::where(['company_id' => $body['company_id'], 'profile' => $body['profile'], 'request_id' => $body['request_id']])->lockForUpdate()->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->request_digest, $body['request_digest']), 409);

                    return response()->json($this->output($existing));
                }
                $authorization = EvidenceAuthorization::create([
                    'profile' => $body['profile'], 'company_id' => $body['company_id'],
                    'suite_tenant_id' => $body['suite_tenant_id'], 'customer_id' => $body['customer_id'],
                    'requester_key_id' => $key->key_id, 'request_id' => $body['request_id'],
                    'operation_id' => $body['operation_id'], 'request_digest' => $body['request_digest'],
                    'request_json' => $body, 'retention_until' => now()->addYears((int) config('change_evidence.retention_years', 7)),
                ]);
                $this->audit($authorization, 'requested', null, null);

                return response()->json($this->output($authorization), 201);
            }, 3);
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }
            abort(409, 'Request or operation is already bound.');
        }
    }

    public function show(Request $request, EvidenceAuthorization $authorization): JsonResponse
    {
        $key = $this->machine($request, $authorization->profile, (int) $authorization->company_id);
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

    public function claim(Request $request, EvidenceAuthorization $authorization): JsonResponse
    {
        $key = $this->machine($request, $authorization->profile, (int) $authorization->company_id);
        $this->originKey($authorization, $key);
        $body = $request->all();
        abort_unless(count($body) === 4 && ! array_diff(array_keys($body), ['purpose', 'nonce', 'ttl_seconds', 'request_digest']), 422);
        abort_unless($body['purpose'] === 'deploy' && is_string($body['nonce']) && Str::isUuid($body['nonce']) && is_int($body['ttl_seconds']) && $body['ttl_seconds'] >= 60 && $body['ttl_seconds'] <= 600, 422);

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
    }

    public function consume(Request $request, EvidenceAuthorization $authorization): JsonResponse
    {
        $key = $this->machine($request, $authorization->profile, (int) $authorization->company_id);
        $this->originKey($authorization, $key);
        $body = $request->all();
        abort_unless(count($body) === 4 && ! array_diff(array_keys($body), ['purpose', 'operation_id', 'request_digest', 'claim_token']), 422);

        return $this->guardedTransaction($authorization, function () use ($body, $authorization): JsonResponse {
            $locked = EvidenceAuthorization::lockForUpdate()->findOrFail($authorization->id);
            $this->current($locked);
            $claim = DB::table('evidence_authorization_claims')->where('authorization_id', $locked->id)->lockForUpdate()->first();
            if ($locked->consumed_at) {
                abort_unless($claim && hash_equals($claim->token_digest, hash('sha256', (string) ($body['claim_token'] ?? ''))) && hash_equals($locked->operation_id, (string) $body['operation_id']) && hash_equals($locked->request_digest, (string) $body['request_digest']), 409);

                return response()->json([...$locked->receipt_json, 'receipt_digest' => $locked->receipt_digest, 'signature' => $locked->signature]);
            }
            abort_unless($locked->status === 'accepted' && $body['purpose'] === 'deploy' && hash_equals($locked->operation_id, (string) $body['operation_id']) && hash_equals($locked->request_digest, (string) $body['request_digest']), 409);
            abort_unless($claim && ! $claim->consumed_at && CarbonImmutable::parse($claim->expires_at)->isFuture() && is_string($body['claim_token'] ?? null) && hash_equals($claim->token_digest, hash('sha256', $body['claim_token'])), 409, 'Claim is absent, expired, consumed, or invalid.');
            $binding = $this->authority($locked->request_json);
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

    private function validateRequest(array $body, array $policy): void
    {
        abort_unless($body['contract_version'] === Registry::CONTRACT && $body['producer'] === $policy['producer'] && $body['target'] === $policy['target'] && $body['environment'] === 'production' && $body['operation'] === 'deploy-release' && $body['purpose'] === 'deploy' && $body['policy_version'] === 'fynix-production-deploy/v2' && $body['rollback_compatible'] === true, 422);
        abort_unless(is_int($body['company_id']) && $body['company_id'] > 0 && is_int($body['itsm_change_id']) && $body['itsm_change_id'] > 0 && is_int($body['itsm_authorization_id']) && $body['itsm_authorization_id'] > 0 && is_int($body['itsm_approval_revision']) && $body['itsm_approval_revision'] >= 0, 422);
        foreach (['suite_tenant_id', 'customer_id', 'request_id', 'operation_id', 'itsm_request_id'] as $field) {
            abort_unless(is_string($body[$field]) && Str::isUuid($body[$field]), 422);
        }
        foreach (['release_sha', 'previous_release_sha'] as $field) {
            abort_unless(is_string($body[$field]) && preg_match('/^[a-f0-9]{40}$/', $body[$field]), 422);
        }
        foreach (['image_digest', 'previous_image_digest'] as $field) {
            abort_unless(is_string($body[$field]) && preg_match('/^sha256:[a-f0-9]{64}$/', $body[$field]), 422);
        }
        foreach (['artifact_digest', 'manifest_digest', 'previous_artifact_digest', 'previous_manifest_digest', 'itsm_binding_digest', 'soak_evidence_sha256', 'readiness_evidence_sha256', 'security_evidence_sha256', 'regression_evidence_sha256', 'request_digest'] as $field) {
            abort_unless(is_string($body[$field]) && preg_match('/^[a-f0-9]{64}$/', $body[$field]), 422);
        }
        abort_unless($body['rollback_ref'] === 'fynix-release:'.$body['previous_release_sha'].'@'.$body['previous_image_digest'].'#sha256:'.$body['previous_artifact_digest'].'/manifest:'.$body['previous_manifest_digest'], 422);
        $unsigned = $body;
        unset($unsigned['request_digest']);
        abort_unless(hash_equals(hash('sha256', $this->canonical($unsigned)), $body['request_digest']), 422);
        abort_unless($body['itsm_contract_version'] === 'fynix-change-authorization/v2' && $body['itsm_profile'] === $body['profile'] && is_int($body['itsm_authority_binding_version']) && $body['itsm_authority_binding_version'] > 0, 422);
        $itsm = ['approval_revision' => $body['itsm_approval_revision'], 'authority_binding_version' => $body['itsm_authority_binding_version'], 'authorization_id' => $body['itsm_authorization_id'], 'change_id' => $body['itsm_change_id'], 'company_id' => $body['company_id'], 'contract_version' => $body['itsm_contract_version'], 'customer_id' => $body['customer_id'], 'policy_version' => $body['policy_version'], 'profile' => $body['itsm_profile'], 'request_id' => $body['itsm_request_id'], 'suite_tenant_id' => $body['suite_tenant_id']];
        abort_unless(hash_equals(hash('sha256', $this->canonical($itsm)), $body['itsm_binding_digest']), 422, 'ITSM binding digest mismatch.');
    }

    private function machine(Request $request, string $profile, mixed $companyId): object
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) >= 32 && strlen($token) <= 4096, 401);
        $key = DB::table('evidence_requester_keys')->where(['token_digest' => hash('sha256', $token), 'company_id' => $companyId, 'profile' => $profile, 'active' => 1])->first();
        abort_unless($key && (! $key->expires_at || CarbonImmutable::parse($key->expires_at)->isFuture()), 403, 'Credential lacks this profile entitlement.');

        return $key;
    }

    private function reviewer(Request $request, EvidenceAuthorization $authorization, string $capability): void
    {
        $permission = $capability === 'can_review' ? 'review change evidence' : 'revoke change evidence';
        abort_unless($request->user() && $request->user()->can($permission) && DB::table('evidence_profile_reviewers')->where(['user_id' => $request->user()->id, 'company_id' => $authorization->company_id, 'profile' => $authorization->profile, 'active' => 1, $capability => 1])->exists(), 403);
    }

    private function authority(array $request): object
    {
        $binding = DB::table('executive_authority_bindings')->where(['company_id' => $request['company_id'], 'authority' => 'executive-hq', 'active' => 1])->first();
        abort_unless($binding && hash_equals($binding->suite_tenant_id, $request['suite_tenant_id']) && hash_equals($binding->customer_id, $request['customer_id']) && (int) $binding->version === (int) $request['itsm_authority_binding_version'], 403, 'Executive authority is not current.');

        return $binding;
    }

    private function current(EvidenceAuthorization $authorization): void
    {
        $this->authority($authorization->request_json);
        abort_if($authorization->revoked_at || ($authorization->expires_at && $authorization->expires_at->isPast()), 410);
    }

    private function guardedTransaction(EvidenceAuthorization $authorization, callable $callback): JsonResponse
    {
        try {
            return DB::transaction($callback, 3);
        } catch (HttpException $exception) {
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
        abort_unless(hash_equals($authorization->requester_key_id, $key->key_id), 403, 'Authorization belongs to another requester key.');
    }

    private function liveItsm(array $request): void
    {
        $base = config('change_evidence.itsm_authority_url');
        abort_unless(is_string($base) && preg_match('#^https://[A-Za-z0-9.-]+(?::443)?(?:/[A-Za-z0-9._/-]*)?$#', $base), 503);
        $url = rtrim($base, '/').'/change-authorizations/'.$request['itsm_authorization_id'];
        try {
            $response = Http::withoutRedirecting()->acceptJson()->withToken($this->ownerOnlyFile(config('change_evidence.itsm_api_key_file'), 4096))->timeout(10)->get($url);
        } catch (\Throwable) {
            abort(503, 'Live ITSM authority unavailable.');
        }
        abort_unless($response->successful() && $response->effectiveUri()->__toString() === $url && strlen($response->body()) <= 65536, 503, 'Live ITSM authority unavailable.');
        $outer = $response->json();
        abort_unless(is_array($outer) && array_keys($outer) === ['data'] && is_array($outer['data']), 503);
        $row = $outer['data'];
        $fields = ['id', 'change_id', 'request_id', 'profile', 'company_id', 'suite_tenant_id', 'customer_id', 'policy_version', 'approval_revision', 'authority_binding_version', 'binding_digest', 'revoked', 'expires_at'];
        abort_unless(count($row) === count($fields) && ! array_diff(array_keys($row), $fields), 503);
        abort_unless($row['id'] === $request['itsm_authorization_id'] && $row['change_id'] === $request['itsm_change_id'] && $row['request_id'] === $request['itsm_request_id'] && $row['profile'] === $request['itsm_profile'] && $row['company_id'] === $request['company_id'] && $row['suite_tenant_id'] === $request['suite_tenant_id'] && $row['customer_id'] === $request['customer_id'] && $row['policy_version'] === $request['policy_version'] && $row['approval_revision'] === $request['itsm_approval_revision'] && $row['authority_binding_version'] === $request['itsm_authority_binding_version'] && is_string($row['binding_digest']) && hash_equals($request['itsm_binding_digest'], $row['binding_digest']) && $row['revoked'] === false && CarbonImmutable::parse($row['expires_at'])->isFuture(), 403, 'Live ITSM authorization is not current.');
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

    private function audit(EvidenceAuthorization $authorization, string $action, ?int $actor, ?string $detail): void
    {
        $previous = DB::table('evidence_authorization_audit')->where('authorization_id', $authorization->id)->orderByDesc('id')->value('event_digest');
        $digest = hash('sha256', implode(':', [$previous ?? '', $authorization->id, $action, $actor ?? '', $detail ?? $authorization->request_digest, now()->toIso8601String(), Str::uuid()]));
        DB::table('evidence_authorization_audit')->insert(['authorization_id' => $authorization->id, 'company_id' => $authorization->company_id, 'profile' => $authorization->profile, 'actor_user_id' => $actor, 'action' => $action, 'previous_digest' => $previous, 'event_digest' => $digest, 'created_at' => now()]);
    }

    private function output(EvidenceAuthorization $authorization): array
    {
        return ['id' => $authorization->id, 'contract_version' => Registry::CONTRACT, 'profile' => $authorization->profile, 'request_id' => $authorization->request_id, 'request_digest' => $authorization->request_digest, 'status' => $authorization->status, 'expires_at' => $authorization->expires_at?->toIso8601String(), 'revoked_at' => $authorization->revoked_at?->toIso8601String(), 'consumed_at' => $authorization->consumed_at?->toIso8601String(), 'retention_until' => $authorization->retention_until->toIso8601String()];
    }
}
