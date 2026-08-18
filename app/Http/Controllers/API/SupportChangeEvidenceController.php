<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SupportChangeEvidenceAcceptance as A;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupportChangeEvidenceController extends Controller
{
    private const V = 'fynix-cyberaudit-acceptance-request/v2';

    private const F = ['contract_version', 'company_id', 'suite_tenant_id', 'customer_id', 'producer', 'request_id', 'target', 'environment', 'operation', 'purpose', 'operation_id', 'support_sha', 'devops_sha', 'image_digest', 'readiness_sha256', 'emission_sha256', 'itsm_binding_digest', 'evidence_digest'];

    public function store(Request $r): JsonResponse
    {
        abort_if(strlen($r->getContent()) > 65536, 413);
        $d = $r->all();
        $this->machine($r, is_int($d['company_id'] ?? null) ? $d['company_id'] : null);
        abort_unless(count($d) === count(self::F) + 1 && ! array_diff(array_keys($d), array_merge(self::F, ['request_digest'])), 422);
        abort_unless(($d['contract_version'] ?? '') === self::V && ($d['producer'] ?? '') === 'fynix-support' && ($d['target'] ?? '') === 'fynix-devops-observability' && ($d['environment'] ?? '') === 'production' && ($d['operation'] ?? '') === 'activate-monitoring' && in_array($d['purpose'] ?? '', ['soak_start', 'deploy'], true), 422);
        foreach (['support_sha', 'devops_sha'] as $k) {
            abort_unless(preg_match('/^[a-f0-9]{40}$/', $d[$k] ?? ''), 422);
        }foreach (['readiness_sha256', 'emission_sha256', 'itsm_binding_digest', 'evidence_digest'] as $k) {
            abort_unless(preg_match('/^[a-f0-9]{64}$/', $d[$k] ?? ''), 422);
        }
        abort_unless(is_int($d['company_id'] ?? null) && $d['company_id'] > 0, 422);
        foreach (['suite_tenant_id', 'customer_id', 'request_id', 'operation_id'] as $k) {
            abort_unless(is_string($d[$k] ?? null) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $d[$k]), 422);
        }
        abort_unless(preg_match('/^sha256:[a-f0-9]{64}$/', $d['image_digest'] ?? ''), 422);
        abort_unless(hash_equals($this->itsmBindingDigest($d), $d['itsm_binding_digest']), 422, 'ITSM binding digest mismatch.');
        $expected = hash('sha256', $this->canonical(array_intersect_key($d, array_flip(self::F))));
        abort_unless(hash_equals($expected, $d['request_digest'] ?? ''), 422);
        $this->binding($d);

        try {
            return DB::transaction(function () use ($d, $expected) {
                $q = A::where(['company_id' => $d['company_id'], 'producer' => $d['producer'], 'request_id' => $d['request_id']])->lockForUpdate()->first();
                if ($q) {
                    abort_unless(hash_equals($q->request_digest, $expected), 409);

                    return response()->json($this->out($q));
                }
                $q = A::create(['company_id' => $d['company_id'], 'suite_tenant_id' => $d['suite_tenant_id'], 'customer_id' => $d['customer_id'], 'producer' => $d['producer'], 'request_id' => $d['request_id'], 'purpose' => $d['purpose'], 'operation_id' => $d['operation_id'], 'request_digest' => $expected, 'request_json' => $d]);
                $this->audit($q, 'requested', null, $expected);

                return response()->json($this->out($q), 201);
            });
        } catch (QueryException $e) {
            if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }
            $q = A::where(['company_id' => $d['company_id'], 'producer' => $d['producer'], 'request_id' => $d['request_id']])->first();
            abort_unless($q && hash_equals($q->request_digest, $expected), 409, 'Request or operation is already bound.');

            return response()->json($this->out($q));
        }
    }

    public function show(Request $r, A $acceptance): JsonResponse
    {
        $this->machine($r, (int) $acceptance->company_id);
        try {
            $this->current($acceptance);
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 403) {
                $this->persistAuthorityInvalidation($acceptance->id);
            }
            throw $e;
        }

        return response()->json($this->out($acceptance));
    }

    public function accept(Request $r, A $acceptance): JsonResponse
    {
        return $this->decide($r, $acceptance, 'accepted');
    }

    public function reject(Request $r, A $acceptance): JsonResponse
    {
        return $this->decide($r, $acceptance, 'rejected');
    }

    public function revoke(Request $r, A $acceptance): JsonResponse
    {
        $this->reviewer($r, $acceptance, 'can_revoke');

        return $this->guardedTransaction($acceptance, function () use ($r, $acceptance) {
            $a = A::lockForUpdate()->findOrFail($acceptance->id);
            $this->current($a);
            abort_unless($a->status === 'accepted' && ! $a->consumed_at && $a->reviewed_by !== $r->user()->id, 409);
            $a->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $r->user()->id]);
            $this->audit($a, 'revoked', $r->user()->id, $a->request_digest);

            return response()->json($this->out($a));
        });
    }

    public function consume(Request $r, A $acceptance): JsonResponse
    {
        $this->machine($r, (int) $acceptance->company_id);
        $b = $r->all();
        abort_unless(count($b) === 3 && ! array_diff(array_keys($b), ['purpose', 'operation_id', 'request_digest']), 422);

        return $this->guardedTransaction($acceptance, function () use ($b, $acceptance) {
            $a = A::lockForUpdate()->findOrFail($acceptance->id);
            $this->current($a);
            $s = $a->request_json;
            abort_unless($s['purpose'] === $b['purpose'] && $s['operation_id'] === $b['operation_id'] && hash_equals($a->request_digest, $b['request_digest']), 409);
            if ($a->consumed_at) {
                return response()->json([...$a->receipt_json, 'receipt_digest' => $a->receipt_digest, 'signature' => $a->signature]);
            }abort_unless($a->status === 'accepted' && $a->expires_at->isFuture(), 409);
            $bind = $this->binding($s);
            [$keyId, $signingKey] = $this->signingIdentity();
            $receipt = ['version' => 'fynix-cyberaudit-acceptance/v2', 'origin' => 'fynix-cyberaudit', ...$s, 'accepted' => true, 'observed_at' => now()->toIso8601String(), 'requested_at' => $a->created_at->toIso8601String(), 'reviewed_at' => $a->reviewed_at->toIso8601String(), 'issued_at' => now()->toIso8601String(), 'expires_at' => $a->expires_at->toIso8601String(), 'consumed_at' => now()->toIso8601String(), 'reviewer_id' => hash('sha256', 'user:'.$a->reviewed_by), 'authority' => 'executive-hq', 'authority_binding_version' => $bind->version, 'authority_binding_verified_at' => $bind->verified_at, 'authority_binding_digest' => $this->canonicalDigest($bind), 'key_id' => $keyId];
            $digest = hash('sha256', $this->canonical($receipt));
            $sig = rtrim(strtr(base64_encode(sodium_crypto_sign_detached(hex2bin($digest), $signingKey)), '+/', '-_'), '=');
            $a->update(['consumed_at' => now(), 'receipt_json' => $receipt, 'receipt_digest' => $digest, 'signature' => $sig, 'key_id' => $receipt['key_id']]);
            $this->audit($a, 'consumed', null, $digest);

            return response()->json([...$receipt, 'receipt_digest' => $digest, 'signature' => $sig]);
        });
    }

    private function decide(Request $r, A $a, string $status): JsonResponse
    {
        $this->reviewer($r, $a, 'can_review');

        return $this->guardedTransaction($a, function () use ($r, $a, $status) {
            $x = A::lockForUpdate()->findOrFail($a->id);
            $this->current($x);
            abort_unless($x->status === 'pending', 409);
            $ttl = (int) config('change_evidence.ttl_seconds');
            abort_unless($ttl >= 60 && $ttl <= 600, 503);
            $x->update(['status' => $status, 'reviewed_by' => $r->user()->id, 'reviewed_at' => now(), 'expires_at' => now()->addSeconds($ttl)]);
            $this->audit($x, $status, $r->user()->id, $x->request_digest);

            return response()->json($this->out($x));
        });
    }

    private function reviewer(Request $r, A $a, string $cap): void
    {
        $permission = $cap === 'can_review' ? 'review support change evidence' : 'revoke support change evidence';
        abort_unless($r->user() && $r->user()->can($permission) && DB::table('support_change_evidence_reviewers')->where(['user_id' => $r->user()->id, 'company_id' => $a->company_id, 'active' => 1, $cap => 1])->exists(), 403);
    }

    private function machine(Request $r, ?int $companyId): void
    {
        $k = $this->secret('requester_key_file');
        abort_unless($r->bearerToken() && hash_equals($k, $r->bearerToken()), 401);
        $scope = (int) config('change_evidence.requester_company_id');
        abort_unless($scope > 0 && $companyId === $scope, 403, 'Requester credential is not scoped to this company.');
    }

    private function binding(array $s): object
    {
        $b = DB::table('executive_authority_bindings')->where(['company_id' => $s['company_id'], 'authority' => 'executive-hq', 'active' => 1])->first();
        abort_unless($b && hash_equals($b->suite_tenant_id, $s['suite_tenant_id']) && hash_equals($b->customer_id, $s['customer_id']), 403);

        return $b;
    }

    private function current(A $a): void
    {
        $this->binding($a->request_json);
        abort_if($a->revoked_at || ($a->expires_at && $a->expires_at->isPast()), 410);
    }

    private function guardedTransaction(A $acceptance, callable $callback): JsonResponse
    {
        try {
            return DB::transaction($callback);
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 403) {
                $this->persistAuthorityInvalidation($acceptance->id);
            }
            throw $e;
        }
    }

    private function secret(string $n): string
    {
        $p = config('change_evidence.'.$n);
        abort_unless(is_string($p) && str_starts_with($p, '/') && ! is_link($p) && is_file($p) && (fileperms($p) & 077) === 0, 503);

        abort_if(filesize($p) > 4096, 503);
        $value = trim((string) file_get_contents($p));
        abort_unless(strlen($value) >= 32, 503);

        return $value;
    }

    private function signingIdentity(): array
    {
        $k = base64_decode($this->secret('signing_key_file'), true);
        abort_unless(is_string($k) && strlen($k) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 503);
        $id = config('change_evidence.signing_key_id');
        abort_unless(is_string($id) && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $id), 503);
        $set = json_decode($this->secret('signing_public_keys_file'), true);
        abort_unless(is_array($set) && count($set) >= 1 && count($set) <= 2 && isset($set[$id]), 503);
        foreach ($set as $keyId => $encoded) {
            $public = is_string($encoded) ? base64_decode($encoded, true) : false;
            abort_unless(is_string($keyId) && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $keyId) && is_string($public) && strlen($public) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 503);
        }
        abort_unless(hash_equals(base64_encode(sodium_crypto_sign_publickey_from_secretkey($k)), $set[$id]), 503);

        return [$id, $k];
    }

    private function canonical(array $v): string
    {
        ksort($v);

        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function canonicalDigest(object $b): string
    {
        return hash('sha256', $this->canonical(['authority' => 'executive-hq', 'company_id' => (int) $b->company_id, 'customer_id' => $b->customer_id, 'suite_tenant_id' => $b->suite_tenant_id, 'verified_at' => $b->verified_at, 'version' => (int) $b->version]));
    }

    private function itsmBindingDigest(array $s): string
    {
        return hash('sha256', $this->canonical(['company_id' => $s['company_id'], 'contract_version' => 'fynix-change-authorization/v1', 'customer_id' => $s['customer_id'], 'devops_sha' => $s['devops_sha'], 'emission_sha256' => $s['emission_sha256'], 'environment' => 'production', 'image_digest' => $s['image_digest'], 'operation' => 'activate-monitoring', 'producer' => 'fynix-support', 'readiness_sha256' => $s['readiness_sha256'], 'request_id' => $s['request_id'], 'suite_tenant_id' => $s['suite_tenant_id'], 'support_sha' => $s['support_sha'], 'target' => 'fynix-devops-observability']));
    }

    private function persistAuthorityInvalidation(int $id): void
    {
        try {
            DB::transaction(function () use ($id): void {
                $a = A::lockForUpdate()->findOrFail($id);
                $a->update(['status' => 'revoked', 'revoked_at' => $a->revoked_at ?? now()]);
                $this->audit($a, 'authority_revoked', null, $a->request_digest);
            });
        } catch (\Throwable $e) {
            throw new RuntimeException('CyberAudit authority invalidation failed.', 0, $e);
        }
    }

    private function audit(A $a, string $action, ?int $actor, string $d): void
    {
        DB::table('support_change_evidence_audit')->insert(['acceptance_id' => $a->id, 'actor_user_id' => $actor, 'action' => $action, 'details_digest' => hash('sha256', $action.$d), 'created_at' => now()]);
    }

    private function out(A $a): array
    {
        return ['id' => $a->id, 'contract_version' => self::V, 'request_id' => $a->request_id, 'request_digest' => $a->request_digest, 'status' => $a->status, 'expires_at' => $a->expires_at?->toIso8601String(), 'revoked_at' => $a->revoked_at?->toIso8601String(), 'consumed_at' => $a->consumed_at?->toIso8601String()];
    }
}
