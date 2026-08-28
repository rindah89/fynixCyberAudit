<?php

namespace Tests\Feature;

use App\Models\DispositionReceipt;
use App\Models\PrivacyRequest;
use App\Suite\SuiteEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class GovernanceDomainEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_hr_purge_event_creates_digest_bound_privacy_completion(): void
    {
        Config::set('data_governance.bindings.hr', [
            'enabled' => true,
            'tenant_id' => 'tenant-1',
            'webhook_id' => 'hr-governance-hook',
            'secret' => str_repeat('s', 32),
            'replay_tolerance' => 300,
        ]);
        $personId = (string) Str::uuid();
        $envelope = [
            'event_type' => 'hr.person.purged',
            'tenant_id' => 'tenant-1',
            'entity_type' => 'person',
            'entity_id' => $personId,
            'occurred_at' => now()->utc()->toAtomString(),
            'payload' => ['person_uuid' => $personId, 'erasure' => true],
        ];

        $response = $this->postSigned($envelope, 'hr', 'hr-governance-hook', str_repeat('s', 32));

        $response->assertOk()->assertJsonPath('outcome', 'governance evidence recorded');
        $request = PrivacyRequest::query()->sole();
        $this->assertSame('closed', $request->status);
        $this->assertSame('pending_review', $request->review_status);
        $this->assertSame(hash('sha256', json_encode($envelope, JSON_UNESCAPED_SLASHES)), $request->evidence_sha256);
    }

    public function test_signed_docflow_destruction_creates_reviewable_disposition_receipt(): void
    {
        Config::set('data_governance.bindings.docflow', [
            'enabled' => true, 'tenant_id' => 'tenant-1', 'webhook_id' => 'docflow-hook',
            'secret' => str_repeat('d', 32), 'replay_tolerance' => 300,
        ]);
        $documentId = (string) Str::uuid();
        $sha = str_repeat('a', 64);
        $envelope = [
            'event_type' => 'docflow.records.destroyed', 'tenant_id' => 'tenant-1',
            'entity_type' => 'document', 'entity_id' => $documentId,
            'occurred_at' => now()->utc()->toAtomString(),
            'payload' => [
                'record_class' => 'incoming', 'retention_days' => 30,
                'record_created_at' => now()->subDays(31)->toAtomString(), 'action' => 'delete',
                'evidence_ref' => 'urn:fynix:docflow:disposition:'.$documentId,
                'evidence_sha256' => $sha,
            ],
        ];

        $response = $this->postSigned($envelope, 'docflow', 'docflow-hook', str_repeat('d', 32));

        $response->assertOk()->assertJsonPath('outcome', 'governance evidence recorded');
        $receipt = DispositionReceipt::query()->sole();
        $this->assertSame($documentId, $receipt->record_ref);
        $this->assertSame($sha, $receipt->evidence_sha256);
        $this->assertSame('pending_review', $receipt->review_status);
    }

    public function test_signed_hr_dsar_export_creates_access_completion(): void
    {
        Config::set('data_governance.bindings.hr', [
            'enabled' => true, 'tenant_id' => 'tenant-1', 'webhook_id' => 'hr-governance-hook',
            'secret' => str_repeat('s', 32), 'replay_tolerance' => 300,
        ]);
        $personId = (string) Str::uuid();
        $sha = str_repeat('b', 64);
        $envelope = [
            'event_type' => 'hr.person.dsar_exported', 'tenant_id' => 'tenant-1',
            'entity_type' => 'person', 'entity_id' => $personId,
            'occurred_at' => now()->utc()->toAtomString(),
            'payload' => [
                'person_uuid' => $personId, 'right' => 'access',
                'completed_at' => now()->utc()->toAtomString(),
                'evidence_ref' => 'urn:fynix:hr:dsar:'.$personId,
                'evidence_sha256' => $sha,
            ],
        ];

        $this->postSigned($envelope, 'hr', 'hr-governance-hook', str_repeat('s', 32))
            ->assertOk()->assertJsonPath('outcome', 'governance evidence recorded');

        $request = PrivacyRequest::query()->sole();
        $this->assertSame('access', $request->right);
        $this->assertSame($sha, $request->evidence_sha256);
        $this->assertSame('pending_review', $request->review_status);
    }

    private function postSigned(array $envelope, string $source, string $webhookId, string $secret)
    {
        $raw = (string) json_encode($envelope, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $deliveryId = (string) Str::uuid();
        $signature = SuiteEnvelope::sign($secret, $timestamp, $envelope['event_type'], $source, $webhookId, $deliveryId, $raw);

        return $this->call('POST', '/api/suite/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_FYNIX_SIGNATURE' => $signature, 'HTTP_X_FYNIX_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_FYNIX_EVENT' => $envelope['event_type'], 'HTTP_X_FYNIX_SOURCE' => $source,
            'HTTP_X_FYNIX_WEBHOOK_ID' => $webhookId, 'HTTP_X_FYNIX_DELIVERY_ID' => $deliveryId,
        ], $raw);
    }
}
