<?php

namespace Tests\Feature;

use App\Models\DataProcessor;
use App\Models\GovernanceControlEvidence;
use App\Models\ProcessorInventoryRun;
use App\Models\RecoveryEvidence;
use App\Models\RetentionRunEvidence;
use App\Models\User;
use App\Suite\DataGovernanceControlService;
use App\Suite\GovernanceOversightService;
use App\Suite\GovernanceReviewIntegrityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GovernanceControlReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_authorized_reviewer_approves_recovery_evidence_with_an_immutable_digest(): void
    {
        $reviewer = User::factory()->create();
        $reviewer->assignRole('Internal Auditor');
        Sanctum::actingAs($reviewer);
        $evidence = RecoveryEvidence::create([
            'tenant_id' => 'tenant-1', 'source' => 'ppm', 'kind' => 'restore_drill',
            'occurred_at' => now()->subDay(), 'outcome' => 'successful',
            'evidence_ref' => 'evidence://ppm/restore/drill-42',
            'evidence_sha256' => str_repeat('a', 64), 'review_status' => 'pending_review',
        ]);

        $this->postJson('/api/governance/control-reviews', [
            'resource_type' => 'recovery_evidence', 'resource_id' => $evidence->id,
            'decision' => 'approved', 'review_evidence_ref' => 'evidence://cyberaudit/review/42',
            'review_evidence_sha256' => str_repeat('b', 64), 'notes' => 'Restore log and checksum verified.',
        ])->assertCreated()->assertJsonPath('outcome', 'approved');

        $this->assertDatabaseHas('recovery_evidence', ['id' => $evidence->id, 'review_status' => 'approved', 'reviewed_by' => $reviewer->id]);
        $this->assertDatabaseHas('governance_control_reviews', [
            'resource_type' => 'recovery_evidence', 'resource_id' => $evidence->id,
            'decision' => 'approved', 'reviewer_id' => $reviewer->id,
        ]);
        $this->assertSame(64, strlen((string) $evidence->fresh()->review_digest));
        $this->assertTrue(app(DataGovernanceControlService::class)->hasCurrentRecoveryEvidence('tenant-1', 'ppm', now()));
        $evidence->update(['evidence_sha256' => str_repeat('f', 64)]);
        $this->assertFalse(app(DataGovernanceControlService::class)->hasCurrentRecoveryEvidence('tenant-1', 'ppm', now()));
        config([
            'data_governance.required_sources' => ['ppm'],
            'data_governance.bindings.ppm' => ['enabled' => true, 'tenant_id' => 'tenant-1', 'webhook_id' => 'webhook-1', 'secret' => str_repeat('x', 32)],
        ]);
        $this->assertSame(1, app(GovernanceOversightService::class)->report()['sources']['ppm']['operability']['invalid_or_tampered_reviews']);
    }

    public function test_unprivileged_user_cannot_review_and_rejected_processor_does_not_pass(): void
    {
        $processor = DataProcessor::create([
            'tenant_id' => 'tenant-1', 'source' => 'office', 'name' => 'AI Provider',
            'purpose' => 'Assistance', 'data_categories' => ['document_content'],
            'processing_countries' => ['US'], 'transfer_mechanism' => 'SCC',
            'agreement_owner' => 'DPO', 'agreement_evidence_ref' => 'evidence://office/dpa/provider',
            'agreement_evidence_sha256' => str_repeat('c', 64), 'review_due_at' => now()->addYear(),
            'status' => 'pending_review',
        ]);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/governance/control-reviews', [
            'resource_type' => 'processor', 'resource_id' => $processor->id, 'decision' => 'approved',
            'review_evidence_ref' => 'evidence://cyberaudit/review/provider',
            'review_evidence_sha256' => str_repeat('d', 64),
        ])->assertForbidden();
        $this->assertSame('pending_review', $processor->fresh()->status);
    }

    public function test_reviewer_approves_control_evidence_and_integrity_detects_tampering(): void
    {
        $reviewer = User::factory()->create();
        $reviewer->assignRole('Internal Auditor');
        Sanctum::actingAs($reviewer);
        $evidence = GovernanceControlEvidence::create([
            'tenant_id' => 'tenant-1', 'source' => 'hr', 'control_id' => 'DG-05',
            'source_evidence_ref' => '11111111-1111-4111-8111-111111111111',
            'observed_at' => now()->subMinute(),
            'evidence_ref' => 'urn:fynix:hr:audit-control:11111111-1111-4111-8111-111111111111',
            'evidence_sha256' => str_repeat('a', 64), 'review_status' => 'pending_review',
        ]);

        $this->postJson('/api/governance/control-reviews', [
            'resource_type' => 'control_evidence', 'resource_id' => $evidence->id,
            'decision' => 'approved', 'review_evidence_ref' => 'urn:fynix:cyberaudit:review:control-1',
            'review_evidence_sha256' => str_repeat('b', 64),
        ])->assertCreated()->assertJsonPath('outcome', 'approved');

        $this->assertTrue(app(GovernanceReviewIntegrityService::class)
            ->approved($evidence->fresh(), 'control_evidence'));
        $evidence->update(['evidence_sha256' => str_repeat('f', 64)]);
        $this->assertFalse(app(GovernanceReviewIntegrityService::class)
            ->approved($evidence->fresh(), 'control_evidence'));
    }

    public function test_review_rejects_missing_or_mismatched_evidence_digest(): void
    {
        $reviewer = User::factory()->create();
        $reviewer->assignRole('Internal Auditor');
        Sanctum::actingAs($reviewer);
        $evidence = RecoveryEvidence::create([
            'tenant_id' => 'tenant-1', 'source' => 'ppm', 'kind' => 'restore_drill',
            'occurred_at' => now()->subDay(), 'outcome' => 'successful',
            'evidence_ref' => 'evidence://ppm/restore/drill-43',
            'evidence_sha256' => str_repeat('e', 64), 'review_status' => 'pending_review',
        ]);

        $this->postJson('/api/governance/control-reviews', [
            'resource_type' => 'recovery_evidence', 'resource_id' => $evidence->id,
            'decision' => 'approved', 'review_evidence_ref' => 'evidence://cyberaudit/review/43',
            'review_evidence_sha256' => 'not-a-sha256',
        ])->assertUnprocessable();
        $this->assertSame('pending_review', $evidence->fresh()->review_status);
    }

    public function test_reviewer_certifies_only_a_complete_approved_processor_register(): void
    {
        $reviewer = User::factory()->create();
        $reviewer->assignRole('Internal Auditor');
        Sanctum::actingAs($reviewer);
        foreach (['Hosting', 'Email'] as $index => $name) {
            DataProcessor::create([
                'tenant_id' => 'tenant-1', 'source' => 'finance', 'name' => $name,
                'purpose' => 'Operations', 'data_categories' => ['financial_records'],
                'processing_countries' => [], 'transfer_mechanism' => null,
                'agreement_owner' => 'DPO', 'agreement_evidence_ref' => 'evidence://finance/dpa/'.strtolower($name),
                'agreement_evidence_sha256' => str_repeat((string) ($index + 1), 64),
                'review_due_at' => now()->addYear(), 'status' => 'pending_review',
            ]);
            $processor = DataProcessor::where(['tenant_id' => 'tenant-1', 'source' => 'finance', 'name' => $name])->firstOrFail();
            $this->postJson('/api/governance/control-reviews', [
                'resource_type' => 'processor', 'resource_id' => $processor->id, 'decision' => 'approved',
                'review_evidence_ref' => 'evidence://cyberaudit/review/processor/'.strtolower($name),
                'review_evidence_sha256' => str_repeat((string) ($index + 3), 64),
            ])->assertCreated();
        }

        $this->postJson('/api/governance/processor-register-reviews', [
            'tenant_id' => 'tenant-1', 'source' => 'finance', 'expected_processor_count' => 1,
            'valid_until' => now()->addYear()->toDateString(),
            'review_evidence_ref' => 'evidence://cyberaudit/processor-register/finance',
            'review_evidence_sha256' => str_repeat('a', 64),
        ])->assertConflict();

        $this->postJson('/api/governance/processor-register-reviews', [
            'tenant_id' => 'tenant-1', 'source' => 'finance', 'expected_processor_count' => 2,
            'valid_until' => now()->addYear()->toDateString(),
            'review_evidence_ref' => 'evidence://cyberaudit/processor-register/finance',
            'review_evidence_sha256' => str_repeat('a', 64),
        ])->assertCreated()->assertJsonPath('processor_count', 2);

        ProcessorInventoryRun::create([
            'status' => 'successful', 'source_count' => 9, 'active_count' => 2,
            'inventory_digest' => str_repeat('b', 64), 'completed_at' => now(),
        ]);

        $this->assertTrue(app(DataGovernanceControlService::class)->hasCurrentProcessorRegister('tenant-1', 'finance', now()));

        DataProcessor::where('tenant_id', 'tenant-1')->where('source', 'finance')->where('name', 'Hosting')
            ->update(['purpose' => 'Changed without review']);
        $this->assertFalse(app(DataGovernanceControlService::class)->hasCurrentProcessorRegister('tenant-1', 'finance', now()));
    }

    public function test_independent_reviewer_approves_digest_bound_retention_run(): void
    {
        $reviewer = User::factory()->create();
        $reviewer->assignRole('Internal Auditor');
        Sanctum::actingAs($reviewer);
        $run = RetentionRunEvidence::create([
            'tenant_id' => 'tenant-1', 'source' => 'finance',
            'source_run_ref' => '11111111-1111-4111-8111-111111111111',
            'schema_fingerprint' => str_repeat('a', 64),
            'schedule_sha256' => str_repeat('b', 64),
            'policy_count' => 398, 'eligible_count' => 12, 'disposed_count' => 10,
            'held_count' => 2, 'preserved_policy_count' => 40, 'pending_outbox_count' => 0,
            'outcome' => 'successful', 'occurred_at' => now()->subMinute(),
            'evidence_ref' => 'urn:fynix:finance:retention-run:11111111-1111-4111-8111-111111111111',
            'evidence_sha256' => str_repeat('c', 64), 'review_status' => 'pending_review',
        ]);

        $this->assertFalse(app(DataGovernanceControlService::class)->hasCurrentRetentionRun(
            'tenant-1', 'finance', now(), str_repeat('b', 64), 398,
        ));
        $this->postJson('/api/governance/control-reviews', [
            'resource_type' => 'retention_run', 'resource_id' => $run->id,
            'decision' => 'approved',
            'review_evidence_ref' => 'evidence://cyberaudit/review/retention-run-1',
            'review_evidence_sha256' => str_repeat('d', 64),
            'notes' => 'Schedule, schema coverage, legal holds, and empty outbox verified.',
        ])->assertCreated()->assertJsonPath('outcome', 'approved');

        $this->assertTrue(app(DataGovernanceControlService::class)->hasCurrentRetentionRun(
            'tenant-1', 'finance', now(), str_repeat('b', 64), 398,
        ));
        $run->update(['disposed_count' => 11]);
        $this->assertFalse(app(DataGovernanceControlService::class)->hasCurrentRetentionRun(
            'tenant-1', 'finance', now(), str_repeat('b', 64), 398,
        ));
    }
}
