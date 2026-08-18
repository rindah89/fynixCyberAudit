<?php

namespace Tests\Feature;

use App\Models\EvidenceAuthorization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EvidencePolicyRegistryTest extends TestCase
{
    use RefreshDatabase;

    private string $signingFile;

    private string $publicKey;

    private string $publicKeysFile;

    private string $itsmKeyFile;

    private string $token = 'profile-entitled-machine-token-000000000001';

    private array $currentItsmBody = [];

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->publicKey = sodium_crypto_sign_publickey($pair);
        $this->signingFile = tempnam(sys_get_temp_dir(), 'evidence-v3-signing-');
        file_put_contents($this->signingFile, base64_encode(sodium_crypto_sign_secretkey($pair)));
        chmod($this->signingFile, 0600);
        $this->publicKeysFile = tempnam(sys_get_temp_dir(), 'evidence-v3-public-');
        file_put_contents($this->publicKeysFile, json_encode(['ev3' => base64_encode($this->publicKey)], JSON_THROW_ON_ERROR));
        chmod($this->publicKeysFile, 0600);
        Config::set('change_evidence.signing_key_file', $this->signingFile);
        Config::set('change_evidence.signing_public_keys_file', $this->publicKeysFile);
        Config::set('change_evidence.signing_key_id', 'ev3');
        Config::set('change_evidence.ttl_seconds', 600);
        Config::set('change_evidence.retention_years', 7);
        $this->itsmKeyFile = tempnam(sys_get_temp_dir(), 'evidence-v3-itsm-');
        file_put_contents($this->itsmKeyFile, str_repeat('i', 32));
        chmod($this->itsmKeyFile, 0600);
        Config::set('change_evidence.itsm_authority_url', 'https://itsm.fynixhq.com/api/v1');
        Config::set('change_evidence.itsm_api_key_file', $this->itsmKeyFile);
        Http::fake(function () {
            $body = $this->currentItsmBody;

            return Http::response(['data' => ['id' => $body['itsm_authorization_id'], 'change_id' => $body['itsm_change_id'], 'request_id' => $body['itsm_request_id'], 'profile' => $body['itsm_profile'], 'company_id' => $body['company_id'], 'suite_tenant_id' => $body['suite_tenant_id'], 'customer_id' => $body['customer_id'], 'policy_version' => $body['policy_version'], 'approval_revision' => $body['itsm_approval_revision'], 'authority_binding_version' => $body['itsm_authority_binding_version'], 'binding_digest' => $body['itsm_binding_digest'], 'revoked' => false, 'expires_at' => now()->addHour()->toIso8601String()]], 200);
        });
        DB::table('executive_authority_bindings')->insert(['company_id' => 1, 'suite_tenant_id' => '00000000-0000-0000-0000-000000000001', 'customer_id' => '4b982d36-4437-4f19-a51d-709a9ccfae8f', 'authority' => 'executive-hq', 'version' => 7, 'active' => 1, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->entitle('fynix-cyberaudit/deploy-release');
    }

    protected function tearDown(): void
    {
        @unlink($this->signingFile);
        @unlink($this->publicKeysFile);
        @unlink($this->itsmKeyFile);
        parent::tearDown();
    }

    public function test_both_exact_profiles_are_closed_and_entitled_per_key(): void
    {
        $this->submit($this->body())->assertCreated()->assertJsonPath('profile', 'fynix-cyberaudit/deploy-release');
        $hq = $this->body('fynix-executive-hq/deploy-release');
        $this->submit($hq)->assertForbidden();
        DB::table('evidence_requester_keys')->where('key_id', 'machine-1')->delete();
        $this->entitle('fynix-executive-hq/deploy-release');
        $this->submit($hq)->assertCreated();

        $unknown = $this->body();
        $unknown['profile'] = 'caller/chosen';
        $unknown = $this->redigest($unknown);
        $this->submit($unknown)->assertUnprocessable();
        $extra = [...$this->body(), 'caller_release' => 'mapped'];
        $this->submit($extra)->assertUnprocessable();
    }

    public function test_release_rollback_itsm_and_evidence_bindings_are_immutable(): void
    {
        $body = $this->body();
        $first = $this->submit($body)->assertCreated();
        $this->submit($body)->assertOk()->assertJsonPath('id', $first->json('id'));
        foreach (['release_sha', 'image_digest', 'artifact_digest', 'manifest_digest', 'previous_release_sha', 'previous_image_digest', 'previous_artifact_digest', 'previous_manifest_digest', 'soak_evidence_sha256', 'security_evidence_sha256', 'regression_evidence_sha256'] as $field) {
            $changed = $body;
            $changed[$field] = $field === 'image_digest' || $field === 'previous_image_digest' ? 'sha256:'.str_repeat('9', 64) : (str_contains($field, 'release') ? str_repeat('9', 40) : str_repeat('9', 64));
            if (str_starts_with($field, 'previous_')) {
                $changed['rollback_ref'] = 'fynix-release:'.$changed['previous_release_sha'].'@'.$changed['previous_image_digest'].'#sha256:'.$changed['previous_artifact_digest'].'/manifest:'.$changed['previous_manifest_digest'];
            }
            $changed = $this->redigest($changed);
            $this->submit($changed)->assertConflict();
        }
        $bad = $this->body();
        $bad['itsm_change_id']++;
        $bad = $this->redigest($bad);
        $this->submit($bad)->assertUnprocessable();

        $live = $this->body();
        $this->currentItsmBody = $live;
        $forged = $live;
        $forged['itsm_authorization_id'] = 999;
        $forged['itsm_binding_digest'] = $this->itsmDigest($forged);
        $forged = $this->redigest($forged);
        $this->withToken($this->token)->postJson('/api/evidence-authorizations', $forged)->assertForbidden();
    }

    public function test_independent_tenant_profile_reviewer_revoker_and_live_authority(): void
    {
        Permission::findOrCreate('review change evidence');
        Permission::findOrCreate('revoke change evidence');
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('review change evidence');
        $reviewer->givePermissionTo('revoke change evidence');
        $this->reviewer($reviewer, true, true);
        $revoker = User::factory()->create();
        $revoker->givePermissionTo('revoke change evidence');
        $this->reviewer($revoker, false, true);
        $id = $this->submit($this->body())->json('id');
        $this->actingAs($reviewer)->postJson("/api/evidence-authorizations/$id/accept")->assertOk();
        $this->actingAs($reviewer)->postJson("/api/evidence-authorizations/$id/revoke")->assertConflict();
        $this->actingAs($revoker)->postJson("/api/evidence-authorizations/$id/revoke")->assertOk();
        $this->withToken($this->token)->getJson("/api/evidence-authorizations/$id")->assertGone();

        $id = $this->submit($this->body())->json('id');
        DB::table('executive_authority_bindings')->where('company_id', 1)->update(['active' => 0]);
        $this->actingAs($reviewer)->postJson("/api/evidence-authorizations/$id/accept")->assertForbidden();
        $this->assertDatabaseHas('evidence_authorizations', ['id' => $id, 'status' => 'revoked']);
    }

    public function test_ed25519_online_claim_is_one_time_and_replay_exact(): void
    {
        Permission::findOrCreate('review change evidence');
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('review change evidence');
        $this->reviewer($reviewer, true, false);
        $body = $this->body();
        $id = $this->submit($body)->json('id');
        $this->actingAs($reviewer)->postJson("/api/evidence-authorizations/$id/accept")->assertOk();
        $peerToken = 'profile-entitled-peer-token-0000000000000001';
        DB::table('evidence_requester_keys')->insert(['key_id' => 'machine-peer', 'token_digest' => hash('sha256', $peerToken), 'company_id' => 1, 'profile' => $body['profile'], 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken($peerToken)->getJson("/api/evidence-authorizations/$id")->assertForbidden();
        $claimBody = ['purpose' => 'deploy', 'nonce' => (string) Str::uuid(), 'ttl_seconds' => 600, 'request_digest' => $body['request_digest']];
        $this->withToken($peerToken)->postJson("/api/evidence-authorizations/$id/claims", $claimBody)->assertForbidden();
        $claim = $this->withToken($this->token)->postJson("/api/evidence-authorizations/$id/claims", $claimBody)->assertCreated()->json();
        $this->withToken($this->token)->postJson("/api/evidence-authorizations/$id/claims", [...$claimBody, 'nonce' => (string) Str::uuid()])->assertConflict();
        $consume = ['purpose' => 'deploy', 'operation_id' => $body['operation_id'], 'request_digest' => $body['request_digest'], 'claim_token' => $claim['claim_token']];
        $first = $this->withToken($this->token)->postJson("/api/evidence-authorizations/$id/consume", $consume)->assertOk()->json();
        $second = $this->withToken($this->token)->postJson("/api/evidence-authorizations/$id/consume", $consume)->assertOk()->json();
        $this->assertSame($first, $second);
        $this->withToken($this->token)->postJson("/api/evidence-authorizations/$id/consume", [...$consume, 'claim_token' => str_repeat('0', 64)])->assertConflict();
        $this->assertTrue(sodium_crypto_sign_verify_detached($this->decode($first['signature']), hex2bin($first['receipt_digest']), $this->publicKey));
        $this->assertSame($body['itsm_binding_digest'], $first['itsm_binding_digest']);
        $this->assertSame(7, $first['authority_binding_version']);
        $this->assertSame(1, DB::table('evidence_authorization_audit')->where(['authorization_id' => $id, 'action' => 'consumed'])->count());
        $this->assertDatabaseHas('evidence_authorization_claims', ['authorization_id' => $id]);
    }

    public function test_expiry_wrong_claim_cross_tenant_and_deletion_fail_closed(): void
    {
        Permission::findOrCreate('review change evidence');
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('review change evidence');
        $this->reviewer($reviewer, true, false);
        $body = $this->body();
        $id = $this->submit($body)->json('id');
        $this->actingAs($reviewer)->postJson("/api/evidence-authorizations/$id/accept")->assertOk();
        $claim = $this->withToken($this->token)->postJson("/api/evidence-authorizations/$id/claims", ['purpose' => 'deploy', 'nonce' => (string) Str::uuid(), 'ttl_seconds' => 60, 'request_digest' => $body['request_digest']])->json();
        $consume = ['purpose' => 'deploy', 'operation_id' => $body['operation_id'], 'request_digest' => $body['request_digest'], 'claim_token' => str_repeat('f', 64)];
        $this->withToken($this->token)->postJson("/api/evidence-authorizations/$id/consume", $consume)->assertConflict();
        $this->travel(61)->seconds();
        $consume['claim_token'] = $claim['claim_token'];
        $this->withToken($this->token)->postJson("/api/evidence-authorizations/$id/consume", $consume)->assertConflict();
        $this->travelBack();
        $this->expectException(QueryException::class);
        EvidenceAuthorization::findOrFail($id)->delete();
    }

    private function body(string $profile = 'fynix-cyberaudit/deploy-release'): array
    {
        $producer = $profile === 'fynix-cyberaudit/deploy-release' ? 'fynix-cyberaudit' : 'fynix-executive-hq';
        $request = ['contract_version' => 'fynix-cyberaudit-evidence-authorization-request/v3', 'profile' => $profile, 'company_id' => 1, 'suite_tenant_id' => '00000000-0000-0000-0000-000000000001', 'customer_id' => '4b982d36-4437-4f19-a51d-709a9ccfae8f', 'producer' => $producer, 'request_id' => (string) Str::uuid(), 'target' => $producer, 'environment' => 'production', 'operation' => 'deploy-release', 'purpose' => 'deploy', 'operation_id' => (string) Str::uuid(), 'policy_version' => 'fynix-production-deploy/v2', 'release_sha' => str_repeat('a', 40), 'image_digest' => 'sha256:'.str_repeat('b', 64), 'artifact_digest' => str_repeat('c', 64), 'manifest_digest' => str_repeat('d', 64), 'previous_release_sha' => str_repeat('e', 40), 'previous_image_digest' => 'sha256:'.str_repeat('f', 64), 'previous_artifact_digest' => str_repeat('1', 64), 'previous_manifest_digest' => str_repeat('2', 64), 'rollback_ref' => '', 'rollback_compatible' => true, 'itsm_contract_version' => 'fynix-change-authorization/v2', 'itsm_profile' => $profile, 'itsm_request_id' => (string) Str::uuid(), 'itsm_change_id' => 42, 'itsm_authorization_id' => 81, 'itsm_approval_revision' => 3, 'itsm_authority_binding_version' => 7, 'itsm_binding_digest' => '', 'soak_evidence_sha256' => str_repeat('3', 64), 'readiness_evidence_sha256' => str_repeat('4', 64), 'security_evidence_sha256' => str_repeat('5', 64), 'regression_evidence_sha256' => str_repeat('6', 64), 'request_digest' => ''];
        $request['rollback_ref'] = 'fynix-release:'.$request['previous_release_sha'].'@'.$request['previous_image_digest'].'#sha256:'.$request['previous_artifact_digest'].'/manifest:'.$request['previous_manifest_digest'];
        $request['itsm_binding_digest'] = $this->itsmDigest($request);

        return $this->redigest($request);
    }

    private function redigest(array $request): array
    {
        unset($request['request_digest']);
        ksort($request);
        $request['request_digest'] = hash('sha256', json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $request;
    }

    private function itsmDigest(array $b): string
    {
        $value = ['approval_revision' => $b['itsm_approval_revision'], 'authority_binding_version' => $b['itsm_authority_binding_version'], 'authorization_id' => $b['itsm_authorization_id'], 'change_id' => $b['itsm_change_id'], 'company_id' => $b['company_id'], 'contract_version' => $b['itsm_contract_version'], 'customer_id' => $b['customer_id'], 'policy_version' => $b['policy_version'], 'profile' => $b['itsm_profile'], 'request_id' => $b['itsm_request_id'], 'suite_tenant_id' => $b['suite_tenant_id']];
        ksort($value);

        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function entitle(string $profile): void
    {
        DB::table('evidence_requester_keys')->insert(['key_id' => 'machine-1', 'token_digest' => hash('sha256', $this->token), 'company_id' => 1, 'profile' => $profile, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function reviewer(User $user, bool $review, bool $revoke): void
    {
        DB::table('evidence_profile_reviewers')->insert(['user_id' => $user->id, 'company_id' => 1, 'profile' => 'fynix-cyberaudit/deploy-release', 'can_review' => $review, 'can_revoke' => $revoke, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function submit(array $body)
    {
        $this->currentItsmBody = $body;

        return $this->withToken($this->token)->postJson('/api/evidence-authorizations', $body);
    }

    private function decode(string $signature): string
    {
        return base64_decode(strtr($signature, '-_', '+/').str_repeat('=', (4 - strlen($signature) % 4) % 4), true);
    }
}
