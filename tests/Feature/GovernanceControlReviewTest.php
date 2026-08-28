<?php

namespace Tests\Feature;

use App\Models\DataProcessor;
use App\Models\RecoveryEvidence;
use App\Models\User;
use App\Suite\DataGovernanceControlService;
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
                'review_due_at' => now()->addYear(), 'status' => 'approved',
            ]);
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

        $this->assertTrue(app(DataGovernanceControlService::class)->hasCurrentProcessorRegister('tenant-1', 'finance', now()));
    }
}
