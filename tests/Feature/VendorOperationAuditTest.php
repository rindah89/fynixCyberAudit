<?php

namespace Tests\Feature;

use App\Models\VendorOperationEvent;
use App\Suite\SuiteEnvelope;
use App\Suite\VendorOperationAnchor;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Mockery;
use Tests\TestCase;

class VendorOperationAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('suite.support.enabled', true);
        Config::set('suite.support.webhook_id', '44444444-4444-4444-8444-444444444444');
        Config::set('suite.support.webhook_secrets', [str_repeat('s', 32)]);
        Config::set('suite.support.remote_tenant_id', '55555555-5555-5555-8555-555555555555');
        Config::set('suite.support.replay_tolerance', 300);
        Config::set('suite.support.ledger_key', str_repeat('l', 32));
        Config::set('suite.support.integrity_max_age', 86400);
        Config::set('suite.itsm.enabled', false);
    }

    public function test_signed_vendor_operation_is_appended_to_hash_chained_ledger(): void
    {
        $first = $this->postOperation('backup.requested', 'ppm', 'succeeded');
        $first->assertOk()->assertJsonPath('outcome', 'recorded');

        $second = $this->postOperation('restore.prepared', 'docflow', 'succeeded');
        $second->assertOk()->assertJsonPath('outcome', 'recorded');

        $events = VendorOperationEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame(str_repeat('0', 64), $events[0]->previous_hash);
        $this->assertSame($events[0]->event_hash, $events[1]->previous_hash);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $events[1]->event_hash);
        $this->assertSame('backup.requested', $events[0]->action);
        $this->assertSame('vendor:test-operator', $events[0]->operator_subject);

        $this->getJson('/api/suite/ready')
            ->assertJsonPath('vendor_operations.enabled', true)
            ->assertJsonPath('vendor_operations.integrity', 'ok');
    }

    public function test_preflight_validates_support_binding(): void
    {
        $this->artisan('fynix:suite-preflight')->assertSuccessful();

        Config::set('suite.support.webhook_secrets', ['short']);
        $this->artisan('fynix:suite-preflight')->assertFailed();
    }

    public function test_duplicate_delivery_is_idempotent(): void
    {
        $deliveryId = (string) Str::uuid();

        $this->postOperation('backup.requested', 'ppm', 'succeeded', $deliveryId)->assertOk();
        $this->postOperation('backup.requested', 'ppm', 'succeeded', $deliveryId)
            ->assertOk()
            ->assertJsonPath('outcome', 'duplicate ignored');

        $this->assertDatabaseCount('vendor_operation_events', 1);
    }

    public function test_unknown_source_is_rejected_even_when_another_binding_secret_matches(): void
    {
        Config::set('suite.ppm.webhook_secrets', [str_repeat('s', 32)]);

        $response = $this->postOperation(
            'backup.requested',
            'ppm',
            'succeeded',
            source: 'unregistered-source',
        );

        $response->assertUnauthorized()->assertJsonPath('outcome', 'invalid signature');
        $this->assertDatabaseCount('vendor_operation_events', 0);
    }

    public function test_vendor_operation_rows_are_immutable_through_the_model(): void
    {
        $this->postOperation('backup.requested', 'ppm', 'succeeded')->assertOk();
        $event = VendorOperationEvent::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $event->update(['outcome' => 'failed']);
    }

    public function test_integrity_check_detects_out_of_band_tampering(): void
    {
        $this->postOperation('backup.requested', 'ppm', 'succeeded')->assertOk();
        DB::table('vendor_operation_events')->update(['target' => 'tampered']);

        $this->artisan('fynix:vendor-ledger-verify')->assertFailed();

        $this->getJson('/api/suite/ready')
            ->assertJsonPath('vendor_operations.integrity', 'failed');
    }

    public function test_lifecycle_events_can_share_an_operation_id(): void
    {
        $operationId = (string) Str::uuid();

        $this->postOperation('restore.requested', 'docflow', 'requested', operationId: $operationId)->assertOk();
        $this->postOperation('restore.approved', 'docflow', 'approved', operationId: $operationId)->assertOk();

        $this->assertDatabaseCount('vendor_operation_events', 2);
        $this->assertSame(2, VendorOperationEvent::query()->where('operation_id', $operationId)->count());
    }

    public function test_verified_ledger_head_is_exported_with_compliance_object_lock(): void
    {
        Config::set('suite.support.anchor.enabled', true);
        Config::set('suite.support.anchor.bucket', 'immutable-audit-anchors');
        Config::set('suite.support.anchor.prefix', 'vendor-operation-ledger');
        Config::set('suite.support.anchor.key', str_repeat('a', 32));
        Config::set('suite.support.anchor.retention_days', 2555);
        Config::set('suite.support.anchor.kms_key_id', 'alias/fynix-audit');
        $this->postOperation('backup.run', 'ppm', 'succeeded')->assertOk();

        $s3 = Mockery::mock(S3Client::class);
        $s3->shouldReceive('putObject')->once()->with(Mockery::on(function (array $arguments): bool {
            $body = json_decode((string) $arguments['Body'], true, flags: JSON_THROW_ON_ERROR);

            return $arguments['Bucket'] === 'immutable-audit-anchors'
                && $arguments['ObjectLockMode'] === 'COMPLIANCE'
                && $arguments['ServerSideEncryption'] === 'aws:kms'
                && $arguments['SSEKMSKeyId'] === 'alias/fynix-audit'
                && str_starts_with((string) $body['signature'], 'hmac-sha256:')
                && $body['event_count'] === 1;
        }))->andReturn(new Result);
        $anchor = new VendorOperationAnchor;
        $anchor->setClient($s3);
        $this->app->instance(VendorOperationAnchor::class, $anchor);

        $this->artisan('fynix:vendor-ledger-anchor')
            ->expectsOutputToContain('vendor-operation-ledger/')
            ->assertSuccessful();

        $this->getJson('/api/suite/ready')
            ->assertOk()
            ->assertJsonPath('vendor_operations.anchor.enabled', true)
            ->assertJsonPath('vendor_operations.anchor.fresh', true);
    }

    private function postOperation(
        string $action,
        string $target,
        string $outcome,
        ?string $deliveryId = null,
        string $source = 'support',
        ?string $operationId = null,
    ) {
        $requestId = (string) Str::uuid();
        $envelope = [
            'event_type' => 'support.operator.event',
            'tenant_id' => '55555555-5555-5555-8555-555555555555',
            'entity_type' => 'vendor_operation',
            'entity_id' => $requestId,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s+00:00'),
            'payload' => [
                'request_id' => $requestId,
                'operation_id' => $operationId ?? (string) Str::uuid(),
                'operator_subject' => 'vendor:test-operator',
                'action' => $action,
                'target' => $target,
                'outcome' => $outcome,
                'source_ip' => '192.0.2.10',
                'itsm_record' => 'CHG1001',
                'before_sha256' => str_repeat('1', 64),
                'after_sha256' => str_repeat('2', 64),
            ],
        ];
        $raw = json_encode($envelope, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $deliveryId ??= (string) Str::uuid();
        $webhookId = '44444444-4444-4444-8444-444444444444';
        $signature = SuiteEnvelope::sign(
            str_repeat('s', 32),
            $timestamp,
            'support.operator.event',
            $source,
            $webhookId,
            $deliveryId,
            (string) $raw,
        );

        return $this->call('POST', '/api/suite/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_FYNIX_SIGNATURE' => $signature,
            'HTTP_X_FYNIX_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_FYNIX_EVENT' => 'support.operator.event',
            'HTTP_X_FYNIX_SOURCE' => $source,
            'HTTP_X_FYNIX_WEBHOOK_ID' => $webhookId,
            'HTTP_X_FYNIX_DELIVERY_ID' => $deliveryId,
        ], $raw);
    }
}
