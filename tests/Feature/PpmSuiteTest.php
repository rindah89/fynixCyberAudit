<?php

namespace Tests\Feature;

use App\Enums\WorkflowStatus;
use App\Models\Audit;
use App\Models\AuditItem;
use App\Models\Control;
use App\Models\DispositionReceipt;
use App\Models\RemediationTask;
use App\Models\RetentionPolicy;
use App\Models\SuiteEntityLink;
use App\Models\User;
use App\Remediation\Remediation;
use App\Suite\FakePpmClient;
use App\Suite\PpmGateway;
use App\Suite\SuiteEnvelope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class PpmSuiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.remediation', true);
        Config::set('suite.ppm.enabled', true);
        Config::set('suite.ppm.base_url', 'https://ppm.test');
        Config::set('suite.ppm.token', 'pat_test');
        Config::set('suite.ppm.tenant_id', '11111111-1111-1111-1111-111111111111');
        Config::set('suite.ppm.webhook_id', '22222222-2222-2222-2222-222222222222');
        Config::set('suite.ppm.webhook_secrets', ['suite-secret']);
        Config::set('suite.ppm.public_url', 'https://ppm.test');
    }

    public function test_unsigned_suite_event_is_rejected(): void
    {
        $response = $this->postJson('/api/suite/events', [
            'event_type' => 'project.updated',
            'tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entity_type' => 'project',
            'entity_id' => '33333333-3333-3333-3333-333333333333',
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s+00:00'),
            'payload' => ['name' => 'x', 'status' => 'on_hold'],
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('outcome', 'invalid signature');
    }

    public function test_signed_event_with_wrong_ppm_binding_is_rejected(): void
    {
        Config::set('suite.ppm.webhook_id', '33333333-3333-4333-8333-333333333333');

        $response = $this->postSignedPpmEvent('project.updated', '44444444-4444-4444-8444-444444444444', []);

        $response->assertStatus(503)->assertJsonPath('outcome', 'binding disabled');
    }

    public function test_signed_event_from_unknown_source_is_rejected(): void
    {
        $envelope = [
            'event_type' => 'finance.invoice.created',
            'tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entity_type' => 'invoice',
            'entity_id' => '44444444-4444-4444-8444-444444444444',
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s+00:00'),
            'payload' => [],
        ];
        $raw = (string) json_encode($envelope, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $deliveryId = (string) Str::uuid();
        $webhookId = '22222222-2222-2222-2222-222222222222';
        $signature = SuiteEnvelope::sign('suite-secret', $timestamp, $envelope['event_type'], 'finance', $webhookId, $deliveryId, $raw);

        $response = $this->call('POST', '/api/suite/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_FYNIX_SIGNATURE' => $signature, 'HTTP_X_FYNIX_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_FYNIX_EVENT' => $envelope['event_type'], 'HTTP_X_FYNIX_SOURCE' => 'finance',
            'HTTP_X_FYNIX_WEBHOOK_ID' => $webhookId, 'HTTP_X_FYNIX_DELIVERY_ID' => $deliveryId,
        ], $raw);

        $response->assertStatus(400)->assertJsonPath('outcome', 'unsupported source');
    }

    public function test_signed_ppm_disposition_event_becomes_reviewable_governance_evidence(): void
    {
        $recordId = '44444444-4444-4444-8444-444444444444';
        $classId = '55555555-5555-4555-8555-555555555555';
        $sha = str_repeat('a', 64);

        $response = $this->postSignedPpmEvent('ppm.records.disposition_executed', $recordId, [
            'record_class_id' => $classId,
            'retention_days' => 365,
            'record_created_at' => now()->subDays(400)->toAtomString(),
            'action' => 'delete',
            'evidence_ref' => 'urn:fynix:ppm:disposition:'.$recordId,
            'evidence_sha256' => $sha,
        ]);

        $response->assertOk()->assertJsonPath('outcome', 'governance evidence recorded');
        $policy = RetentionPolicy::query()->where('record_class', $classId)->sole();
        $receipt = DispositionReceipt::query()->where('record_ref', $recordId)->sole();
        $this->assertSame($policy->id, $receipt->retention_policy_id);
        $this->assertSame('pending_review', $receipt->review_status);
        $this->assertSame($sha, $receipt->evidence_sha256);
    }

    public function test_publishing_a_poam_creates_a_ppm_project_and_back_link(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Security Admin');
        $client = new FakePpmClient;
        $this->app->instance(FakePpmClient::class, $client);

        $project = app(Remediation::class)->createProject($owner, ['name' => 'Backup vault hardening']);
        $link = app(PpmGateway::class)->publishProject($owner, $project);

        $this->assertSame(1, $client->createProjectCalls);
        $this->assertSame('ppm', $link->system);
        $this->assertSame('project', $link->entity_type);
        $this->assertSame('33333333-3333-3333-3333-333333333333', $link->entity_id);
        $this->assertSame($project->id, $link->local_id);
        $this->assertNotEmpty($client->links);
        $this->assertSame('grc', $client->links[0]['system']);
        $this->assertSame((string) $project->id, $client->links[0]['entity_id']);
        $this->assertNotEmpty($client->outboundEvents);
        $this->assertStringContainsString('grc.remediation.published', $client->outboundEvents[0]['body']);

        $again = app(PpmGateway::class)->publishProject($owner, $project);
        $this->assertSame($link->id, $again->id);
        $this->assertSame(1, $client->createProjectCalls);
    }

    public function test_readiness_fails_when_required_itsm_binding_is_disabled(): void
    {
        Config::set('suite.required_sources', ['itsm']);
        Config::set('suite.itsm.enabled', false);

        $this->getJson('/api/suite/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('missing.0', 'itsm.enabled');
    }

    public function test_readiness_fails_when_required_ppm_binding_is_incomplete(): void
    {
        Config::set('suite.required_sources', ['ppm']);
        Config::set('suite.ppm.token', '');

        $this->getJson('/api/suite/ready')
            ->assertStatus(503)
            ->assertJsonFragment(['ppm.token']);
    }

    public function test_ppm_project_update_projects_status_without_mutating_the_poam(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Security Admin');
        $client = new FakePpmClient;
        $this->app->instance(FakePpmClient::class, $client);

        $project = app(Remediation::class)->createProject($owner, ['name' => 'Laptop encryption']);
        $link = app(PpmGateway::class)->publishProject($owner, $project);
        $client->setProjectStatus($link->entity_id, 'on_hold');

        $response = $this->postSignedPpmEvent('project.updated', $link->entity_id, [
            'name' => 'Laptop encryption',
            'status' => 'on_hold',
        ]);

        $response->assertOk();
        $response->assertJsonPath('outcome', 'applied');
        $this->assertSame('on_hold', $link->fresh()->remote_status);
        $this->assertSame('planning', $project->fresh()->status);
    }

    public function test_audit_task_is_published_as_a_ppm_work_card(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Security Admin');
        $client = new FakePpmClient;
        $this->app->instance(FakePpmClient::class, $client);

        $project = app(Remediation::class)->createProject($owner, ['name' => 'Vault hardening']);
        $audit = Audit::factory()->inProgress()->withManager($owner)->create();
        $item = AuditItem::factory()->create([
            'audit_id' => $audit->id,
            'auditable_type' => Control::class,
            'auditor_notes' => 'Backups are unencrypted.',
            'status' => WorkflowStatus::INPROGRESS,
        ]);

        $task = app(Remediation::class)->createTaskFromAuditItem($owner, $item, $project, [
            'priority' => 'High',
        ]);

        $this->assertCount(1, $client->workPackages);
        $this->assertStringContainsString($task->number, $client->workPackages[0]['title']);
        $this->assertSame('Task', $client->workPackages[0]['type_name'] ?? 'Task');

        $card = SuiteEntityLink::query()
            ->where('local_type', RemediationTask::class)
            ->where('local_id', $task->id)
            ->where('entity_type', 'work_package')
            ->first();

        $this->assertNotNull($card);
        $this->assertStringContainsString('/board', (string) $card->remote_url);

        $response = $this->postSignedPpmEvent(
            'work_package.updated',
            $card->entity_id,
            ['title' => $task->title, 'state' => 'in_progress'],
            'work_package',
        );

        $response->assertOk();
        $response->assertJsonPath('outcome', 'applied');
        $this->assertSame('in_progress', $card->fresh()->remote_status);
        $this->assertSame('Open', $task->fresh()->status);
    }

    /** @param array<string, mixed> $payload */
    private function postSignedPpmEvent(string $eventType, string $entityId, array $payload, string $entityType = 'project')
    {
        $envelope = [
            'event_type' => $eventType,
            'tenant_id' => '11111111-1111-1111-1111-111111111111',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s+00:00'),
            'payload' => $payload,
        ];
        $raw = json_encode($envelope, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $deliveryId = (string) Str::uuid();
        $webhookId = '22222222-2222-2222-2222-222222222222';
        $signature = SuiteEnvelope::sign('suite-secret', $timestamp, $eventType, 'ppm', $webhookId, $deliveryId, (string) $raw);

        return $this->call('POST', '/api/suite/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_FYNIX_SIGNATURE' => $signature,
            'HTTP_X_FYNIX_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_FYNIX_EVENT' => $eventType,
            'HTTP_X_FYNIX_SOURCE' => 'ppm',
            'HTTP_X_FYNIX_WEBHOOK_ID' => $webhookId,
            'HTTP_X_FYNIX_DELIVERY_ID' => $deliveryId,
        ], $raw);
    }
}
