<?php

namespace Tests\Feature;

use App\Models\SupportChangeEvidenceAcceptance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExecutiveAuthorityBindingTest extends TestCase
{
    use RefreshDatabase;

    private string $publicKeysFile;

    private string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKeysFile = tempnam(sys_get_temp_dir(), 'executive-authority-');
        file_put_contents($this->publicKeysFile, json_encode(['hq1' => base64_encode(sodium_crypto_sign_publickey($pair))], JSON_THROW_ON_ERROR));
        chmod($this->publicKeysFile, 0600);
        Config::set('change_evidence.executive_origin', 'https://fynixhq.com');
        Config::set('change_evidence.executive_public_keys_file', $this->publicKeysFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->publicKeysFile);
        parent::tearDown();
    }

    public function test_signed_event_is_idempotent_and_replay_drift_fails_closed(): void
    {
        $event = $this->event();
        $this->send($event)->assertAccepted()->assertJsonPath('outcome', 'applied');
        $this->send($event)->assertOk()->assertJsonPath('outcome', 'applied');
        $event['customer_id'] = (string) Str::uuid();
        $this->send($event)->assertConflict();

        $nonceReplay = $this->event(version: 2);
        $nonceReplay['nonce'] = $event['nonce'];
        $this->send($nonceReplay)->assertConflict();
    }

    public function test_deactivate_then_restore_never_revives_pending_evidence(): void
    {
        $active = $this->event();
        $this->send($active)->assertAccepted();
        $acceptance = SupportChangeEvidenceAcceptance::create(['company_id' => 1, 'suite_tenant_id' => $active['suite_tenant_id'], 'customer_id' => $active['customer_id'], 'producer' => 'fynix-support', 'request_id' => (string) Str::uuid(), 'purpose' => 'deploy', 'operation_id' => (string) Str::uuid(), 'request_digest' => str_repeat('a', 64), 'request_json' => ['company_id' => 1], 'status' => 'pending']);
        DB::table('support_change_evidence_audit')->insert(['acceptance_id' => $acceptance->id, 'company_id' => 1, 'action' => 'requested', 'details_digest' => str_repeat('b', 64), 'created_at' => now()]);
        $disabled = $this->event(version: 2, active: false, tenant: $active['suite_tenant_id'], customer: $active['customer_id']);
        $this->send($disabled)->assertAccepted()->assertJsonPath('outcome', 'deactivated');
        $this->assertDatabaseHas('support_change_evidence_acceptances', ['id' => $acceptance->id, 'status' => 'revoked']);
        $restored = $this->event(version: 3, active: true, tenant: $active['suite_tenant_id'], customer: $active['customer_id']);
        $this->send($restored)->assertAccepted();
        $this->assertDatabaseHas('support_change_evidence_acceptances', ['id' => $acceptance->id, 'status' => 'revoked']);
    }

    public function test_wrong_origin_key_stale_and_future_events_are_denied(): void
    {
        $event = $this->event();
        $this->send($event, origin: 'https://evil.example')->assertUnauthorized();
        $this->send($event, keyId: 'unknown')->assertUnauthorized();
        $event['expires_at'] = now('UTC')->subSecond()->toIso8601String();
        $this->send($event)->assertGone();
        $event = $this->event();
        $event['issued_at'] = now('UTC')->addMinutes(2)->toIso8601String();
        $event['expires_at'] = now('UTC')->addMinutes(3)->toIso8601String();
        $this->send($event)->assertGone();
    }

    private function event(int $version = 1, bool $active = true, ?string $tenant = null, ?string $customer = null): array
    {
        return ['contract_version' => 'fynix-executive-authority-binding/v1', 'event_id' => (string) Str::uuid(), 'nonce' => (string) Str::uuid(), 'company_id' => 1, 'suite_tenant_id' => $tenant ?? (string) Str::uuid(), 'customer_id' => $customer ?? (string) Str::uuid(), 'version' => $version, 'active' => $active, 'issued_at' => now('UTC')->toIso8601String(), 'expires_at' => now('UTC')->addMinutes(5)->toIso8601String()];
    }

    private function send(array $event, string $origin = 'https://fynixhq.com', string $keyId = 'hq1')
    {
        $canonical = $event;
        ksort($canonical);
        $digest = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signature = rtrim(strtr(base64_encode(sodium_crypto_sign_detached(hex2bin($digest), $this->secretKey)), '+/', '-_'), '=');

        return $this->withHeaders(['X-Fynix-Origin' => $origin, 'X-Fynix-Key-Id' => $keyId, 'X-Fynix-Signature' => $signature])->postJson('/api/suite/executive-authority-bindings', $event);
    }
}
