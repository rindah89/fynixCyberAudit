<?php

namespace Tests\Feature;

use App\Models\SupportChangeEvidenceAcceptance;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupportChangeEvidenceAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private string $requesterFile;

    private string $signingFile;

    private string $publicKey;

    private string $publicKeysFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requesterFile = tempnam(sys_get_temp_dir(), 'cyber-requester-');
        $this->signingFile = tempnam(sys_get_temp_dir(), 'cyber-signing-');
        file_put_contents($this->requesterFile, str_repeat('r', 32));
        $pair = sodium_crypto_sign_keypair();
        file_put_contents($this->signingFile, base64_encode(sodium_crypto_sign_secretkey($pair)));
        $this->publicKey = sodium_crypto_sign_publickey($pair);
        $previous = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $this->publicKeysFile = tempnam(sys_get_temp_dir(), 'cyber-public-');
        file_put_contents($this->publicKeysFile, json_encode(['g1' => base64_encode($this->publicKey), 'g0' => base64_encode($previous)], JSON_THROW_ON_ERROR));
        chmod($this->requesterFile, 0600);
        chmod($this->signingFile, 0600);
        chmod($this->publicKeysFile, 0600);
        Config::set('change_evidence', ['requester_company_id' => 1, 'requester_key_file' => $this->requesterFile, 'signing_key_file' => $this->signingFile, 'signing_public_keys_file' => $this->publicKeysFile, 'signing_key_id' => 'g1', 'ttl_seconds' => 600]);
        DB::table('executive_authority_bindings')->insert(['company_id' => 1, 'suite_tenant_id' => '00000000-0000-0000-0000-000000000001', 'customer_id' => '4b982d36-4437-4f19-a51d-709a9ccfae8f', 'authority' => 'executive-hq', 'version' => 1, 'active' => 1, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        @unlink($this->requesterFile);
        @unlink($this->signingFile);
        @unlink($this->publicKeysFile);
        parent::tearDown();
    }

    public function test_machine_request_is_idempotent_and_binding_immutable(): void
    {
        $body = $this->body();
        $first = $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $body)->assertCreated();
        $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $body)->assertOk()->assertJsonPath('id', $first->json('id'));
        $body['evidence_digest'] = str_repeat('b', 64);
        unset($body['request_digest']);
        ksort($body);
        $body['request_digest'] = hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $body)->assertConflict();
        $body = $this->body();
        $body['customer_id'] = (string) Str::uuid();
        $body['itsm_binding_digest'] = $this->itsmBindingDigest($body);
        $body = $this->digestRequest($body);
        $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $body)->assertForbidden();
    }

    public function test_only_authorized_human_can_issue_signed_bounded_receipt(): void
    {
        Permission::findOrCreate('review support change evidence');
        $reviewer = User::factory()->create();
        $this->scope($reviewer, true, false);
        $reviewer->givePermissionTo('review support change evidence');
        $id = $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $this->body())->json('id');
        $this->actingAs($reviewer)->postJson("/api/support-change-evidence/$id/accept")->assertOk();
        $subject = SupportChangeEvidenceAcceptance::findOrFail($id)->request_json;
        $receipt = $this->withToken(str_repeat('r', 32))->postJson("/api/support-change-evidence/$id/consume", Arr::only($subject, ['purpose', 'operation_id', 'request_digest']))->assertOk();
        $receipt->assertJsonPath('accepted', true)->assertJsonPath('key_id', 'g1');
        $unsigned = $receipt->json();
        $signature = $unsigned['signature'];
        $digest = $unsigned['receipt_digest'];
        $this->assertNotEmpty($signature);
        $this->assertSame(64, strlen($digest));
        $this->assertTrue(sodium_crypto_sign_verify_detached($this->decodeSignature($signature), hex2bin($digest), $this->publicKey));
        $this->assertSame($reviewer->id, SupportChangeEvidenceAcceptance::find($id)->reviewed_by);
        $this->withToken(str_repeat('r', 32))->getJson("/api/support-change-evidence/$id")->assertOk()->assertJsonPath('consumed_at', fn ($v) => $v !== null);
        $this->actingAs(User::factory()->create())->postJson("/api/support-change-evidence/$id/accept")->assertForbidden();
    }

    public function test_revocation_requires_separate_authorized_actor_and_is_immediate(): void
    {
        Permission::findOrCreate('review support change evidence');
        Permission::findOrCreate('revoke support change evidence');
        $reviewer = User::factory()->create();
        $this->scope($reviewer, true, true);
        $reviewer->givePermissionTo('review support change evidence');
        $revoker = User::factory()->create();
        $this->scope($revoker, false, true);
        $revoker->givePermissionTo('revoke support change evidence');
        $id = $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $this->body())->json('id');
        $this->actingAs($reviewer)->postJson("/api/support-change-evidence/$id/accept")->assertOk();
        $reviewer->givePermissionTo('revoke support change evidence');
        $this->actingAs($reviewer)->postJson("/api/support-change-evidence/$id/revoke")->assertConflict();
        $this->actingAs($revoker)->postJson("/api/support-change-evidence/$id/revoke")->assertOk();
        $this->withToken(str_repeat('r', 32))->getJson("/api/support-change-evidence/$id")->assertGone();
    }

    public function test_expired_receipt_and_unsafe_secret_file_fail_closed(): void
    {
        Permission::findOrCreate('review support change evidence');
        $reviewer = User::factory()->create();
        $this->scope($reviewer, true, false);
        $reviewer->givePermissionTo('review support change evidence');
        $id = $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $this->body())->json('id');
        $this->actingAs($reviewer)->postJson("/api/support-change-evidence/$id/accept")->assertOk();
        $this->travel(601)->seconds();
        $this->withToken(str_repeat('r', 32))->getJson("/api/support-change-evidence/$id")->assertGone();
        $this->travelBack();

        chmod($this->requesterFile, 0644);
        $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $this->body())->assertServerError();
    }

    public function test_authority_deactivation_is_durable_and_restore_does_not_revive(): void
    {
        Permission::findOrCreate('review support change evidence');
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('review support change evidence');
        $this->scope($reviewer, true, false);
        $body = $this->body();
        $id = $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $body)->json('id');
        $this->actingAs($reviewer)->postJson("/api/support-change-evidence/$id/accept")->assertOk();
        DB::table('executive_authority_bindings')->where('company_id', 1)->update(['active' => 0]);
        $consume = Arr::only($body, ['purpose', 'operation_id', 'request_digest']);
        $this->withToken(str_repeat('r', 32))->postJson("/api/support-change-evidence/$id/consume", $consume)->assertForbidden();
        $this->assertDatabaseHas('support_change_evidence_acceptances', ['id' => $id, 'status' => 'revoked']);
        DB::table('executive_authority_bindings')->where('company_id', 1)->update(['active' => 1]);
        $this->withToken(str_repeat('r', 32))->postJson("/api/support-change-evidence/$id/consume", $consume)->assertGone();
    }

    public function test_operation_is_unique_and_reviewer_needs_rbac_plus_tenant_scope(): void
    {
        $first = $this->body();
        $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $first)->assertCreated();
        $second = $this->body();
        $second['operation_id'] = $first['operation_id'];
        $second = $this->digestRequest($second);
        $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $second)->assertConflict();

        Permission::findOrCreate('review support change evidence');
        $reviewer = User::factory()->create();
        $this->scope($reviewer, true, false);
        $id = SupportChangeEvidenceAcceptance::firstOrFail()->id;
        $this->actingAs($reviewer)->postJson("/api/support-change-evidence/$id/accept")->assertForbidden();
        $reviewer->givePermissionTo('review support change evidence');
        DB::table('support_change_evidence_reviewers')->where('user_id', $reviewer->id)->update(['company_id' => 2]);
        $this->actingAs($reviewer)->postJson("/api/support-change-evidence/$id/accept")->assertForbidden();
    }

    public function test_rejection_is_terminal_and_wrong_itsm_digest_is_denied(): void
    {
        Permission::findOrCreate('review support change evidence');
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('review support change evidence');
        $this->scope($reviewer, true, false);
        $body = $this->body();
        $bad = $body;
        $bad['itsm_binding_digest'] = str_repeat('0', 64);
        $bad = $this->digestRequest($bad);
        $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $bad)->assertUnprocessable();
        $id = $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $body)->json('id');
        $this->actingAs($reviewer)->postJson("/api/support-change-evidence/$id/reject")->assertOk()->assertJsonPath('status', 'rejected');
        $this->actingAs($reviewer)->postJson("/api/support-change-evidence/$id/accept")->assertConflict();
    }

    public function test_machine_auth_closed_schema_and_body_limit_fail_closed(): void
    {
        $this->withToken('wrong')->postJson('/api/support-change-evidence', $this->body())->assertUnauthorized();
        $crossTenant = $this->body();
        $crossTenant['company_id'] = 2;
        $crossTenant['itsm_binding_digest'] = $this->itsmBindingDigest($crossTenant);
        $crossTenant = $this->digestRequest($crossTenant);
        $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $crossTenant)->assertForbidden();
        $extra = [...$this->body(), 'unexpected' => true];
        $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $extra)->assertUnprocessable();
        $oversized = [...$this->body(), 'padding' => str_repeat('x', 70000)];
        $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $oversized)->assertStatus(413);
    }

    public function test_consumption_replay_is_exact_and_evidence_is_retained(): void
    {
        Permission::findOrCreate('review support change evidence');
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('review support change evidence');
        $this->scope($reviewer, true, false);
        $body = $this->body();
        $id = $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $body)->json('id');
        $this->actingAs($reviewer)->postJson("/api/support-change-evidence/$id/accept")->assertOk();
        $consume = Arr::only($body, ['purpose', 'operation_id', 'request_digest']);
        $first = $this->withToken(str_repeat('r', 32))->postJson("/api/support-change-evidence/$id/consume", $consume)->assertOk()->json();
        $second = $this->withToken(str_repeat('r', 32))->postJson("/api/support-change-evidence/$id/consume", $consume)->assertOk()->json();
        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('support_change_evidence_audit')->where(['acceptance_id' => $id, 'action' => 'consumed'])->count());
        $this->expectException(QueryException::class);
        SupportChangeEvidenceAcceptance::findOrFail($id)->delete();
    }

    public function test_authority_remap_and_signing_key_mismatch_fail_closed(): void
    {
        Permission::findOrCreate('review support change evidence');
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('review support change evidence');
        $this->scope($reviewer, true, false);
        $body = $this->body();
        $id = $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $body)->json('id');
        $this->actingAs($reviewer)->postJson("/api/support-change-evidence/$id/accept")->assertOk();
        DB::table('executive_authority_bindings')->where('company_id', 1)->update(['customer_id' => (string) Str::uuid(), 'version' => 2]);
        $this->withToken(str_repeat('r', 32))->postJson("/api/support-change-evidence/$id/consume", Arr::only($body, ['purpose', 'operation_id', 'request_digest']))->assertForbidden();
        $this->assertDatabaseHas('support_change_evidence_acceptances', ['id' => $id, 'status' => 'revoked']);

        $body = $this->body();
        DB::table('executive_authority_bindings')->where('company_id', 1)->update(['customer_id' => $body['customer_id'], 'version' => 3]);
        $id = $this->withToken(str_repeat('r', 32))->postJson('/api/support-change-evidence', $body)->json('id');
        $this->actingAs($reviewer)->postJson("/api/support-change-evidence/$id/accept")->assertOk();
        $keys = json_decode(file_get_contents($this->publicKeysFile), true, flags: JSON_THROW_ON_ERROR);
        $keys['g1'] = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
        file_put_contents($this->publicKeysFile, json_encode($keys, JSON_THROW_ON_ERROR));
        $this->withToken(str_repeat('r', 32))->postJson("/api/support-change-evidence/$id/consume", Arr::only($body, ['purpose', 'operation_id', 'request_digest']))->assertServiceUnavailable();
    }

    private function body(): array
    {
        $body = ['contract_version' => 'fynix-cyberaudit-acceptance-request/v2', 'company_id' => 1, 'suite_tenant_id' => '00000000-0000-0000-0000-000000000001', 'customer_id' => '4b982d36-4437-4f19-a51d-709a9ccfae8f', 'producer' => 'fynix-support', 'request_id' => (string) Str::uuid(), 'target' => 'fynix-devops-observability', 'environment' => 'production', 'operation' => 'activate-monitoring', 'purpose' => 'soak_start', 'operation_id' => (string) Str::uuid(), 'support_sha' => str_repeat('a', 40), 'devops_sha' => str_repeat('b', 40), 'image_digest' => 'sha256:'.str_repeat('c', 64), 'readiness_sha256' => str_repeat('d', 64), 'emission_sha256' => str_repeat('e', 64), 'evidence_digest' => str_repeat('a', 64)];
        $body['itsm_binding_digest'] = $this->itsmBindingDigest($body);

        return $this->digestRequest($body);
    }

    private function digestRequest(array $body): array
    {
        unset($body['request_digest']);
        ksort($body);
        $body['request_digest'] = hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $body;
    }

    private function itsmBindingDigest(array $body): string
    {
        $itsm = ['company_id' => $body['company_id'], 'contract_version' => 'fynix-change-authorization/v1', 'customer_id' => $body['customer_id'], 'devops_sha' => $body['devops_sha'], 'emission_sha256' => $body['emission_sha256'], 'environment' => 'production', 'image_digest' => $body['image_digest'], 'operation' => 'activate-monitoring', 'producer' => 'fynix-support', 'readiness_sha256' => $body['readiness_sha256'], 'request_id' => $body['request_id'], 'suite_tenant_id' => $body['suite_tenant_id'], 'support_sha' => $body['support_sha'], 'target' => 'fynix-devops-observability'];
        ksort($itsm);

        return hash('sha256', json_encode($itsm, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function decodeSignature(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }

    private function scope(User $user, bool $review, bool $revoke): void
    {
        DB::table('support_change_evidence_reviewers')->insert(['user_id' => $user->id, 'company_id' => 1, 'can_review' => $review, 'can_revoke' => $revoke, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
}
