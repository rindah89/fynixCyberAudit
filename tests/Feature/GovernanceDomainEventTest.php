<?php

namespace Tests\Feature;

use App\Models\DispositionReceipt;
use App\Models\LegalHold;
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

    public function test_ppm_legal_hold_events_are_record_scoped_and_idempotent(): void
    {
        Config::set('suite.ppm.enabled', true);
        Config::set('suite.ppm.tenant_id', '11111111-1111-1111-1111-111111111111');
        Config::set('suite.ppm.webhook_id', 'ppm-hook');
        Config::set('suite.ppm.webhook_secrets', ['suite-secret']);
        $recordId = (string) Str::uuid();
        $holdId = (string) Str::uuid();
        $payload = [
            'record_class_id' => (string) Str::uuid(), 'retention_days' => 365,
            'record_ref' => $recordId, 'source_hold_ref' => $holdId,
        ];
        $placed = [
            'event_type' => 'ppm.records.hold_applied', 'tenant_id' => config('suite.ppm.tenant_id'),
            'entity_type' => 'record', 'entity_id' => $recordId,
            'occurred_at' => now()->utc()->toAtomString(), 'payload' => $payload,
        ];

        $this->postSigned($placed, 'ppm', 'ppm-hook', 'suite-secret')
            ->assertOk()->assertJsonPath('outcome', 'governance evidence recorded');
        $hold = LegalHold::query()->sole();
        $this->assertSame($recordId, $hold->record_ref);
        $this->assertNull($hold->released_at);

        $released = $placed;
        $released['event_type'] = 'ppm.records.hold_released';
        $released['occurred_at'] = now()->addSecond()->utc()->toAtomString();
        $this->postSigned($released, 'ppm', 'ppm-hook', 'suite-secret')
            ->assertOk()->assertJsonPath('outcome', 'governance evidence recorded');
        $this->assertNotNull($hold->refresh()->released_at);
    }

    public function test_finance_privacy_completion_is_queued_for_independent_review(): void
    {
        Config::set('data_governance.bindings.finance', [
            'enabled' => true, 'tenant_id' => 'tenant-1', 'webhook_id' => 'finance-hook',
            'secret' => str_repeat('f', 32), 'replay_tolerance' => 300,
        ]);
        $requestId = (string) Str::uuid();
        $subjectRef = (string) Str::uuid();
        $sha = str_repeat('c', 64);
        $opened = [
            'event_type' => 'finance.privacy.opened', 'tenant_id' => 'tenant-1',
            'entity_type' => 'privacy_request', 'entity_id' => $requestId,
            'occurred_at' => now()->subDays(2)->utc()->toAtomString(),
            'payload' => [
                'subject_ref' => $subjectRef, 'right' => 'deletion',
                'lawful_basis' => 'data_subject_right',
                'requested_at' => now()->subDays(2)->utc()->toAtomString(),
            ],
        ];
        $this->postSigned($opened, 'finance', 'finance-hook', str_repeat('f', 32))
            ->assertOk()->assertJsonPath('outcome', 'governance evidence recorded');
        $centralId = PrivacyRequest::query()->sole()->id;
        $this->assertSame('open', PrivacyRequest::query()->sole()->status);
        $this->assertSame($requestId, PrivacyRequest::query()->sole()->source_request_ref);
        $envelope = [
            'event_type' => 'finance.privacy.completed', 'tenant_id' => 'tenant-1',
            'entity_type' => 'privacy_request', 'entity_id' => $requestId,
            'occurred_at' => now()->utc()->toAtomString(),
            'payload' => [
                'subject_ref' => $subjectRef, 'right' => 'deletion',
                'requested_at' => now()->subDays(2)->utc()->toAtomString(),
                'completed_at' => now()->utc()->toAtomString(),
                'evidence_ref' => 'urn:fynix:finance:privacy:'.$requestId,
                'evidence_sha256' => $sha,
            ],
        ];

        $this->postSigned($envelope, 'finance', 'finance-hook', str_repeat('f', 32))
            ->assertOk()->assertJsonPath('outcome', 'governance evidence recorded');

        $request = PrivacyRequest::query()->sole();
        $this->assertSame($centralId, $request->id);
        $this->assertSame('deletion', $request->right);
        $this->assertSame($sha, $request->evidence_sha256);
        $this->assertSame('pending_review', $request->review_status);
    }

    public function test_itsm_erasure_completion_is_queued_for_independent_review(): void
    {
        Config::set('suite.itsm.enabled', true);
        Config::set('suite.itsm.webhook_id', 'itsm-hook');
        Config::set('suite.itsm.webhook_secret', str_repeat('i', 32));
        Config::set('suite.itsm.remote_tenant_id', 'tenant-1');
        Config::set('suite.itsm.local_tenant_id', 'cyberaudit');
        $subjectRef = (string) Str::uuid();
        $sha = str_repeat('d', 64);
        $envelope = [
            'event_type' => 'itsm.privacy.erasure_completed', 'tenant_id' => 'tenant-1',
            'entity_type' => 'privacy_request', 'entity_id' => $subjectRef,
            'occurred_at' => now()->utc()->toAtomString(),
            'payload' => [
                'subject_ref' => $subjectRef, 'right' => 'deletion',
                'requested_at' => now()->subHour()->utc()->toAtomString(),
                'completed_at' => now()->utc()->toAtomString(),
                'evidence_ref' => 'urn:fynix:itsm:privacy-erasure:'.Str::uuid(),
                'evidence_sha256' => $sha,
            ],
        ];

        $this->postSigned($envelope, 'itsm', 'itsm-hook', str_repeat('i', 32))
            ->assertOk()->assertJsonPath('outcome', 'governance evidence recorded');

        $request = PrivacyRequest::query()->sole();
        $this->assertSame('itsm', $request->source);
        $this->assertSame('deletion', $request->right);
        $this->assertSame($sha, $request->evidence_sha256);
        $this->assertSame('pending_review', $request->review_status);
    }

    public function test_ppm_erasure_completion_is_queued_for_independent_review(): void
    {
        Config::set('suite.ppm.enabled', true);
        Config::set('suite.ppm.webhook_id', 'ppm-hook');
        Config::set('suite.ppm.webhook_secrets', [str_repeat('p', 32)]);
        Config::set('suite.ppm.tenant_id', 'tenant-1');
        $subjectRef = (string) Str::uuid();
        $requestId = (string) Str::uuid();
        $sha = str_repeat('e', 64);
        $envelope = [
            'event_type' => 'ppm.privacy.erasure_completed', 'tenant_id' => 'tenant-1',
            'entity_type' => 'privacy_request', 'entity_id' => $requestId,
            'occurred_at' => now()->utc()->toAtomString(),
            'payload' => [
                'subject_ref' => $subjectRef, 'right' => 'deletion',
                'requested_at' => now()->subHour()->utc()->toAtomString(),
                'completed_at' => now()->utc()->toAtomString(),
                'evidence_ref' => 'urn:fynix:ppm:privacy:'.$requestId,
                'evidence_sha256' => $sha,
            ],
        ];

        $this->postSigned($envelope, 'ppm', 'ppm-hook', str_repeat('p', 32))
            ->assertOk()->assertJsonPath('outcome', 'governance evidence recorded');

        $request = PrivacyRequest::query()->sole();
        $this->assertSame('ppm', $request->source);
        $this->assertSame($subjectRef, $request->subject_ref);
        $this->assertSame($sha, $request->evidence_sha256);
        $this->assertSame('pending_review', $request->review_status);
    }

    public function test_ppm_access_export_closes_the_correlated_request(): void
    {
        Config::set('suite.ppm.enabled', true);
        Config::set('suite.ppm.webhook_id', 'ppm-hook');
        Config::set('suite.ppm.webhook_secrets', [str_repeat('p', 32)]);
        Config::set('suite.ppm.tenant_id', 'tenant-1');
        $subjectRef = (string) Str::uuid();
        $requestId = (string) Str::uuid();
        $occurredAt = now()->utc()->toAtomString();

        $this->postSigned([
            'event_type' => 'ppm.privacy.opened', 'tenant_id' => 'tenant-1',
            'entity_type' => 'privacy_request', 'entity_id' => $requestId,
            'occurred_at' => $occurredAt,
            'payload' => [
                'subject_ref' => $subjectRef, 'right' => 'access',
                'lawful_basis' => 'data_subject_right', 'requested_at' => $occurredAt,
            ],
        ], 'ppm', 'ppm-hook', str_repeat('p', 32))->assertOk();

        $sha = str_repeat('a', 64);
        $this->postSigned([
            'event_type' => 'ppm.privacy.access_completed', 'tenant_id' => 'tenant-1',
            'entity_type' => 'privacy_request', 'entity_id' => $requestId,
            'occurred_at' => $occurredAt,
            'payload' => [
                'subject_ref' => $subjectRef, 'right' => 'access',
                'requested_at' => $occurredAt, 'completed_at' => $occurredAt,
                'evidence_ref' => 'urn:fynix:ppm:privacy:'.$requestId.':export',
                'evidence_sha256' => $sha,
            ],
        ], 'ppm', 'ppm-hook', str_repeat('p', 32))->assertOk();

        $this->assertDatabaseCount('privacy_requests', 1);
        $request = PrivacyRequest::query()->sole();
        $this->assertSame('closed', $request->status);
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
