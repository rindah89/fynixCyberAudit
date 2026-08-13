<?php

namespace Tests\Feature;

use App\Ai\StubAiClient;
use App\Enums\Applicability;
use App\Enums\Effectiveness;
use App\Enums\QuotaType;
use App\Enums\WorkflowStatus;
use App\Jobs\PerformAiAuditJob;
use App\Models\Audit;
use App\Models\AuditItem;
use App\Models\Control;
use App\Models\Implementation;
use App\Models\User;
use App\Services\QuotaService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AiAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.ai_audit', true);
    }

    public function test_ai_audit_updates_in_progress_control_items(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');

        $audit = Audit::factory()->inProgress()->withManager($manager)->create();
        $control = Control::factory()->create([
            'title' => 'Encryption at rest',
            'description' => 'Customer data must be encrypted with AES-256-GCM.',
        ]);
        $item = AuditItem::factory()->create([
            'audit_id' => $audit->id,
            'auditable_id' => $control->id,
            'auditable_type' => Control::class,
            'status' => WorkflowStatus::INPROGRESS,
            'effectiveness' => Effectiveness::UNKNOWN,
            'applicability' => Applicability::UNKNOWN,
            'auditor_notes' => null,
        ]);

        $client = new StubAiClient;
        $client->queue(json_encode([
            'effectiveness' => 'Effective',
            'applicability' => 'Applicable',
            'confidence' => 'HIGH',
            'needs_human_review' => false,
            'notes' => 'Implementations and policy cover AES-256-GCM.',
        ]));
        $this->app->instance(StubAiClient::class, $client);

        $this->actingAs($manager);
        PerformAiAuditJob::dispatchSync($audit->id, $manager->id);

        $item->refresh();
        $this->assertSame(Effectiveness::EFFECTIVE, $item->effectiveness);
        $this->assertSame(Applicability::APPLICABLE, $item->applicability);
        $this->assertStringContainsString('[AI Assessment - Confidence: HIGH]', (string) $item->auditor_notes);
        $this->assertStringContainsString('AES-256-GCM', (string) $item->auditor_notes);
    }

    public function test_ai_audit_skips_implementation_only_items(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');

        $audit = Audit::factory()->inProgress()->withManager($manager)->create();
        $implementation = Implementation::factory()->create();
        $item = AuditItem::factory()->create([
            'audit_id' => $audit->id,
            'auditable_id' => $implementation->id,
            'auditable_type' => Implementation::class,
            'status' => WorkflowStatus::INPROGRESS,
            'effectiveness' => Effectiveness::UNKNOWN,
            'applicability' => Applicability::UNKNOWN,
            'auditor_notes' => 'untouched',
        ]);

        $client = new StubAiClient;
        $client->queue(json_encode([
            'effectiveness' => 'Effective',
            'applicability' => 'Applicable',
            'confidence' => 'HIGH',
            'needs_human_review' => false,
            'notes' => 'should not write',
        ]));
        $this->app->instance(StubAiClient::class, $client);

        $this->actingAs($manager);
        PerformAiAuditJob::dispatchSync($audit->id, $manager->id);

        $item->refresh();
        $this->assertSame(Effectiveness::UNKNOWN, $item->effectiveness);
        $this->assertSame('untouched', $item->auditor_notes);
        $this->assertSame(0, $client->calls);
    }

    public function test_non_manager_cannot_perform_ai_audit(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $outsider = User::factory()->create();
        $outsider->assignRole('Security Admin');

        $audit = Audit::factory()->inProgress()->withManager($manager)->create();
        $item = AuditItem::factory()->create([
            'audit_id' => $audit->id,
            'auditable_type' => Control::class,
            'effectiveness' => Effectiveness::UNKNOWN,
            'auditor_notes' => null,
        ]);

        $client = new StubAiClient;
        $client->queue('{"effectiveness":"Effective","applicability":"Applicable","confidence":"HIGH","needs_human_review":false,"notes":"x"}');
        $this->app->instance(StubAiClient::class, $client);

        $this->actingAs($outsider);

        try {
            PerformAiAuditJob::dispatchSync($audit->id, $outsider->id);
            $this->fail('Expected non-manager to be refused');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $item->refresh();
        $this->assertSame(Effectiveness::UNKNOWN, $item->effectiveness);
        $this->assertSame(0, $client->calls);
    }

    public function test_quota_exceeded_does_not_mutate_audit_items(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');

        $audit = Audit::factory()->inProgress()->withManager($manager)->create();
        $item = AuditItem::factory()->create([
            'audit_id' => $audit->id,
            'auditable_type' => Control::class,
            'effectiveness' => Effectiveness::UNKNOWN,
            'auditor_notes' => null,
        ]);

        $client = new StubAiClient;
        $client->queue('{"effectiveness":"Effective","applicability":"Applicable","confidence":"HIGH","needs_human_review":false,"notes":"x"}');
        $this->app->instance(StubAiClient::class, $client);

        QuotaService::record(QuotaType::AI_PROMPT, QuotaService::getLimit(QuotaType::AI_PROMPT));

        $this->actingAs($manager);

        try {
            PerformAiAuditJob::dispatchSync($audit->id, $manager->id);
            $this->fail('Expected quota to be refused');
        } catch (\App\Exceptions\QuotaExceededException) {
            $this->assertSame(0, $client->calls);
        }

        $item->refresh();
        $this->assertSame(Effectiveness::UNKNOWN, $item->effectiveness);
        $this->assertNull($item->auditor_notes);
    }

    public function test_invalid_ai_json_does_not_mutate_audit_items(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');

        $audit = Audit::factory()->inProgress()->withManager($manager)->create();
        $item = AuditItem::factory()->create([
            'audit_id' => $audit->id,
            'auditable_type' => Control::class,
            'effectiveness' => Effectiveness::UNKNOWN,
            'auditor_notes' => null,
        ]);

        $client = new StubAiClient;
        $client->queue('this is not json');
        $this->app->instance(StubAiClient::class, $client);

        $this->actingAs($manager);

        try {
            PerformAiAuditJob::dispatchSync($audit->id, $manager->id);
            $this->fail('Expected invalid JSON to fail closed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('invalid JSON', $e->getMessage());
        }

        $item->refresh();
        $this->assertSame(Effectiveness::UNKNOWN, $item->effectiveness);
        $this->assertNull($item->auditor_notes);
    }
}
