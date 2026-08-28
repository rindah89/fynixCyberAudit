<?php

namespace Tests\Feature;

use App\Models\PrivacyRequest;
use App\Models\RetentionRunEvidence;
use App\Models\SuiteAuditEvent;
use App\Suite\SuiteEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class GovernanceControlControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-1111-4111-8111-111111111111';

    private const WEBHOOK = '22222222-2222-4222-8222-222222222222';

    private const SECRET = 'governance-control-secret-32-bytes';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('data_governance.required_sources', ['hr']);
        Config::set('data_governance.bindings.hr', [
            'enabled' => true, 'tenant_id' => self::TENANT, 'webhook_id' => self::WEBHOOK,
            'secret' => self::SECRET, 'replay_tolerance' => 300,
        ]);
    }

    public function test_signed_application_can_open_and_close_a_privacy_request(): void
    {
        $subjectRef = (string) Str::uuid();
        $opened = $this->postSigned('privacy_request.open', [
            'subject_ref' => $subjectRef, 'right' => 'access',
            'lawful_basis' => 'legal_obligation', 'requested_at' => '2026-08-28T10:00:00Z',
        ])->assertCreated()->json();

        $this->postSigned('privacy_request.close', [
            'privacy_request_id' => $opened['resource_id'],
            'evidence_ref' => 'evidence://hr/'.$subjectRef.'/export',
            'evidence_sha256' => str_repeat('a', 64),
        ])->assertOk()->assertJsonPath('outcome', 'recorded');
        $this->assertDatabaseHas('privacy_requests', ['id' => $opened['resource_id'], 'status' => 'closed']);
    }

    public function test_unsigned_command_is_rejected(): void
    {
        $this->postSigned('recovery_evidence.record', [], signature: '')->assertUnauthorized();
    }

    public function test_signed_application_cannot_mutate_another_tenants_resource(): void
    {
        $foreign = PrivacyRequest::create([
            'tenant_id' => 'foreign-tenant', 'source' => 'hr', 'subject_ref' => 'person-99',
            'right' => 'access', 'lawful_basis' => 'legal_obligation', 'status' => 'open',
            'requested_at' => now(), 'due_at' => now()->addDays(30),
        ]);

        $this->postSigned('privacy_request.close', [
            'privacy_request_id' => $foreign->id, 'evidence_ref' => 'evidence://foreign/export', 'evidence_sha256' => str_repeat('b', 64),
        ])->assertNotFound();
        $this->assertDatabaseHas('privacy_requests', ['id' => $foreign->id, 'status' => 'open']);
    }

    public function test_payload_cannot_override_signed_tenant_source_or_server_lifecycle(): void
    {
        $response = $this->postSigned('privacy_request.open', [
            'tenant_id' => 'foreign', 'source' => 'finance', 'status' => 'closed',
            'due_at' => now()->addYears(10), 'subject_ref' => (string) Str::uuid(), 'right' => 'access',
            'lawful_basis' => 'legal_obligation', 'requested_at' => '2026-08-28T10:00:00Z',
        ])->assertCreated();
        $this->assertDatabaseHas('privacy_requests', [
            'id' => $response->json('resource_id'), 'tenant_id' => self::TENANT, 'source' => 'hr', 'status' => 'open',
        ]);
    }

    public function test_missing_command_fields_return_a_bounded_validation_error(): void
    {
        $this->postSigned('legal_hold.place', [])->assertUnprocessable()->assertJsonPath('outcome', 'invalid command');
    }

    public function test_sensitive_or_url_like_references_are_rejected(): void
    {
        $this->postSigned('privacy_request.open', [
            'subject_ref' => 'person@example.test', 'right' => 'access', 'lawful_basis' => 'legal_obligation',
        ])->assertUnprocessable();
        $this->postSigned('recovery_evidence.record', [
            'kind' => 'restore_drill', 'occurred_at' => now(), 'outcome' => 'successful',
            'evidence_ref' => 'https://example.test/report?token=secret',
        ])->assertUnprocessable();
    }

    public function test_signed_application_records_an_append_only_pseudonymous_audit_event(): void
    {
        $eventRef = (string) Str::uuid();
        $subjectRef = (string) Str::uuid();
        $response = $this->postSigned('audit_event.record', [
            'source_event_ref' => $eventRef,
            'subject_ref' => $subjectRef,
            'action' => 'authorization.denied',
            'outcome' => 'denied',
            'correlation_ref' => (string) Str::uuid(),
            'occurred_at' => now()->subSecond()->toAtomString(),
            'email' => 'must-not-be-stored@example.test',
        ])->assertOk();

        $event = SuiteAuditEvent::findOrFail($response->json('resource_id'));
        $this->assertSame(self::TENANT, $event->tenant_id);
        $this->assertSame('hr', $event->source);
        $this->assertSame($subjectRef, $event->subject_ref);
        $this->assertSame(64, strlen($event->event_sha256));
        $this->assertFalse(array_key_exists('email', $event->getAttributes()));

        $this->expectException(\LogicException::class);
        $event->update(['outcome' => 'succeeded']);
    }

    public function test_signed_application_records_complete_retention_run_for_independent_review(): void
    {
        $runRef = (string) Str::uuid();
        $response = $this->postSigned('retention_run.record', [
            'source_run_ref' => $runRef,
            'schema_fingerprint' => str_repeat('a', 64),
            'schedule_sha256' => str_repeat('b', 64),
            'policy_count' => 398,
            'eligible_count' => 12,
            'disposed_count' => 10,
            'held_count' => 2,
            'preserved_policy_count' => 40,
            'pending_outbox_count' => 0,
            'outcome' => 'successful',
            'occurred_at' => now()->subSecond()->toAtomString(),
            'evidence_ref' => 'urn:fynix:finance:retention-run:'.$runRef,
            'evidence_sha256' => str_repeat('c', 64),
        ])->assertOk()->assertJsonPath('outcome', 'recorded');

        $run = RetentionRunEvidence::findOrFail($response->json('resource_id'));
        $this->assertSame(self::TENANT, $run->tenant_id);
        $this->assertSame('hr', $run->source);
        $this->assertSame('pending_review', $run->review_status);
        $this->assertSame(0, $run->pending_outbox_count);
    }

    public function test_incomplete_or_future_retention_run_is_rejected(): void
    {
        $payload = [
            'source_run_ref' => (string) Str::uuid(),
            'schema_fingerprint' => str_repeat('a', 64),
            'schedule_sha256' => str_repeat('b', 64),
            'policy_count' => 398,
            'eligible_count' => 1,
            'disposed_count' => 0,
            'held_count' => 0,
            'preserved_policy_count' => 40,
            'pending_outbox_count' => 1,
            'outcome' => 'successful',
            'occurred_at' => now()->addDay()->toAtomString(),
            'evidence_ref' => 'urn:fynix:finance:retention-run:invalid',
            'evidence_sha256' => str_repeat('d', 64),
        ];

        $this->postSigned('retention_run.record', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('retention_run_evidence', 0);
    }

    private function postSigned(string $command, array $payload, ?string $signature = null)
    {
        $body = json_encode(['tenant_id' => self::TENANT, 'command' => $command, 'payload' => $payload], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = time();
        $deliveryId = (string) Str::uuid();
        $signature ??= SuiteEnvelope::sign(self::SECRET, $timestamp, 'governance.control.commanded', 'hr', self::WEBHOOK, $deliveryId, $body);

        return $this->call('POST', '/api/suite/governance/controls', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_FYNIX_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_FYNIX_EVENT' => 'governance.control.commanded', 'HTTP_X_FYNIX_SOURCE' => 'hr',
            'HTTP_X_FYNIX_WEBHOOK_ID' => self::WEBHOOK, 'HTTP_X_FYNIX_DELIVERY_ID' => $deliveryId,
            'HTTP_X_FYNIX_SIGNATURE' => $signature,
        ], $body);
    }
}
