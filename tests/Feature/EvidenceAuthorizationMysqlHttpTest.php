<?php

namespace Tests\Feature;

use App\ChangeEvidence\EvidenceAuthorizationAuditor;
use App\Models\EvidenceAuthorization;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvidenceAuthorizationMysqlHttpTest extends TestCase
{
    public function test_real_mysql_controller_create_claim_consume_and_replay_responses(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! str_starts_with((string) DB::connection()->getDatabaseName(), 'fynix_evidence_test_')) {
            $this->markTestSkipped('isolated MySQL evidence database required');
        }
        DB::table('evidence_authorization_audit')->delete();
        DB::table('evidence_authorization_claims')->delete();
        DB::table('evidence_authorizations')->delete();
        DB::table('evidence_requester_keys')->delete();
        DB::table('executive_authority_bindings')->delete();
        $token = str_repeat('m', 40);
        DB::table('executive_authority_bindings')->insert(['company_id' => 1, 'suite_tenant_id' => '00000000-0000-0000-0000-000000000001', 'customer_id' => '4b982d36-4437-4f19-a51d-709a9ccfae8f', 'authority' => 'executive-hq', 'version' => 7, 'active' => 1, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('evidence_requester_keys')->insert(['key_id' => 'mysql-http', 'token_digest' => hash('sha256', $token), 'company_id' => 1, 'profile' => 'fynix-cyberaudit/deploy-release', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $pair = sodium_crypto_sign_keypair();
        $signing = tempnam(sys_get_temp_dir(), 'mysql-http-signing-');
        $public = tempnam(sys_get_temp_dir(), 'mysql-http-public-');
        $itsm = tempnam(sys_get_temp_dir(), 'mysql-http-itsm-');
        file_put_contents($signing, base64_encode(sodium_crypto_sign_secretkey($pair)));
        file_put_contents($public, json_encode(['mysql' => base64_encode(sodium_crypto_sign_publickey($pair))], JSON_THROW_ON_ERROR));
        file_put_contents($itsm, str_repeat('i', 32));
        chmod($signing, 0600);
        chmod($public, 0600);
        chmod($itsm, 0600);
        Config::set('change_evidence.signing_key_file', $signing);
        Config::set('change_evidence.signing_public_keys_file', $public);
        Config::set('change_evidence.signing_key_id', 'mysql');
        Config::set('change_evidence.itsm_api_key_file', $itsm);
        Config::set('change_evidence.ttl_seconds', 600);
        $body = ['contract_version' => 'fynix-cyberaudit-evidence-authorization-request/v3', 'profile' => 'fynix-cyberaudit/deploy-release', 'company_id' => 1, 'suite_tenant_id' => '00000000-0000-0000-0000-000000000001', 'customer_id' => '4b982d36-4437-4f19-a51d-709a9ccfae8f', 'producer' => 'fynix-cyberaudit-release', 'request_id' => (string) Str::uuid(), 'target' => 'fynix-cyberaudit', 'environment' => 'production', 'operation' => 'deploy-release', 'purpose' => 'deploy', 'operation_id' => (string) Str::uuid(), 'policy_version' => 'fynix-production-deploy/v2', 'release_sha' => str_repeat('a', 40), 'image_digest' => 'sha256:'.str_repeat('b', 64), 'artifact_sha256' => str_repeat('c', 64), 'manifest_sha256' => str_repeat('d', 64), 'previous_release_sha' => str_repeat('e', 40), 'previous_image_digest' => 'sha256:'.str_repeat('f', 64), 'previous_artifact_sha256' => str_repeat('1', 64), 'rollback_ref' => '', 'itsm_change_id' => 42, 'itsm_authorization_id' => 81, 'itsm_approval_revision' => 3, 'itsm_binding_digest' => '', 'readiness_evidence_sha256' => str_repeat('4', 64), 'soak_receipt_sha256' => str_repeat('2', 64), 'soak_evidence_sha256' => str_repeat('3', 64), 'rollback_compatible' => true, 'request_digest' => ''];
        $body['rollback_ref'] = 'fynix-release:'.$body['previous_release_sha'].'@'.$body['previous_image_digest'].'#sha256:'.$body['previous_artifact_sha256'];
        $immutable = $body;
        foreach (['purpose', 'operation_id', 'policy_version', 'itsm_change_id', 'itsm_authorization_id', 'itsm_approval_revision', 'itsm_binding_digest', 'request_digest'] as $field) {
            unset($immutable[$field]);
        } $immutable['contract_version'] = 'fynix-change-authorization/v2';
        ksort($immutable);
        $body['itsm_binding_digest'] = hash('sha256', json_encode($immutable, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $unsigned = $body;
        unset($unsigned['request_digest']);
        ksort($unsigned);
        $body['request_digest'] = hash('sha256', json_encode($unsigned, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        Http::fake(['https://itsm.fynixhq.com/api/v1/change-authorizations/81' => Http::response(['data' => [...$immutable, 'binding_digest' => $body['itsm_binding_digest'], 'id' => 81, 'change_id' => 42, 'policy_version' => 'fynix-production-deploy/v2', 'approval_revision' => 3, 'revoked' => false, 'created_at' => now()->subMinute()->toIso8601String(), 'expires_at' => now()->addHour()->toIso8601String()]])]);
        $created = $this->withToken($token)->postJson('/api/evidence-authorizations', $body)->assertCreated();
        $id = $created->json('id');
        DB::table('evidence_authorizations')->where('id', $id)->update(['status' => 'accepted', 'reviewed_at' => now(), 'expires_at' => now()->addMinutes(10)]);
        if (! extension_loaded('pcntl')) {
            $this->fail('pcntl is required for the real concurrent HTTP claim test');
        }
        $resultDirectory = sys_get_temp_dir().'/evidence-http-'.Str::uuid();
        mkdir($resultDirectory, 0700);
        $children = [];
        for ($index = 0; $index < 8; $index++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::disconnect();
                DB::reconnect();
                $payload = ['purpose' => 'deploy', 'nonce' => (string) Str::uuid(), 'ttl_seconds' => 600, 'request_digest' => $body['request_digest']];
                $httpRequest = HttpRequest::create("/api/evidence-authorizations/$id/claims", 'POST', [], [], [], [
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                    'HTTP_ACCEPT' => 'application/json',
                    'CONTENT_TYPE' => 'application/json',
                ], json_encode($payload, JSON_THROW_ON_ERROR));
                $response = app(Kernel::class)->handle($httpRequest);
                file_put_contents("$resultDirectory/$index.json", json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR));
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status), 'Concurrent HTTP worker failed.');
        }
        DB::disconnect();
        DB::reconnect();
        $results = collect(glob("$resultDirectory/*.json"))->map(fn (string $file): array => json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR));
        $this->assertCount(1, $results->where('status', 201));
        $this->assertCount(7, $results->where('status', 409));
        $claim = $results->firstWhere('status', 201)['body'];
        $consume = ['purpose' => 'deploy', 'operation_id' => $body['operation_id'], 'request_digest' => $body['request_digest'], 'claim_token' => $claim['claim_token']];
        $first = $this->withToken($token)->postJson("/api/evidence-authorizations/$id/consume", $consume)->assertOk()->json();
        $second = $this->withToken($token)->postJson("/api/evidence-authorizations/$id/consume", $consume)->assertOk()->json();
        $this->assertEquals($first, $second);
        DB::table('evidence_requester_keys')->insert(['key_id' => 'mysql-lifecycle', 'token_digest' => hash('sha256', 'mysql-lifecycle-token'), 'company_id' => 1, 'profile' => 'fynix-cyberaudit/deploy-release', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $lifecycle = EvidenceAuthorization::factory()->create(['company_id' => 1, 'requester_key_id' => 'mysql-lifecycle', 'authority_binding_version' => 7, 'status' => 'accepted']);
        DB::table('evidence_requester_keys')->where('key_id', 'mysql-lifecycle')->update(['active' => 0]);
        $this->assertDatabaseHas('evidence_authorizations', ['id' => $lifecycle->id, 'status' => 'revoked']);
        $this->assertDatabaseHas('evidence_authorization_audit', ['authorization_id' => $lifecycle->id, 'action' => 'credential_revoked', 'reason_code' => 'key_lifecycle']);
        $lifecycleAudit = DB::table('evidence_authorization_audit')->where('authorization_id', $lifecycle->id)->first();
        $canonicalPayload = json_decode($lifecycleAudit->canonical_payload, true, flags: JSON_THROW_ON_ERROR);
        ksort($canonicalPayload);
        $this->assertSame($lifecycleAudit->event_digest, hash('sha256', json_encode($canonicalPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
        $children = [];
        for ($index = 0; $index < 8; $index++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    DB::disconnect();
                    DB::reconnect();
                    app(EvidenceAuthorizationAuditor::class)->denied('concurrent_denied', ['authorization_id' => $lifecycle->id, 'company_id' => 1, 'profile' => 'fynix-cyberaudit/deploy-release', 'worker' => $index]);
                    exit(0);
                } catch (\Throwable) {
                    exit(2);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status), 'Concurrent audit append escaped transaction retry.');
        }
        DB::disconnect();
        DB::reconnect();
        $previous = null;
        $auditEvents = DB::table('evidence_authorization_audit')->where('authorization_id', $lifecycle->id)->orderBy('id')->get();
        $this->assertCount(9, $auditEvents);
        foreach ($auditEvents as $event) {
            $payload = json_decode($event->canonical_payload, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($previous, $payload['previous_digest']);
            ksort($payload);
            $this->assertSame($event->event_digest, hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
            $previous = $event->event_digest;
        }
        foreach (glob("$resultDirectory/*.json") as $file) {
            @unlink($file);
        }
        @rmdir($resultDirectory);
        @unlink($signing);
        @unlink($public);
        @unlink($itsm);
    }
}
