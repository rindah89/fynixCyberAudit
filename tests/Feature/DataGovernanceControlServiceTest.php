<?php

namespace Tests\Feature;

use App\Models\RecoveryEvidence;
use App\Models\User;
use App\Suite\DataGovernanceControlService;
use App\Suite\GovernanceControlReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DataGovernanceControlServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_request_lifecycle_is_due_dated_and_auditable(): void
    {
        $service = app(DataGovernanceControlService::class);
        $request = $service->openPrivacyRequest([
            'tenant_id' => 'tenant-1',
            'source' => 'hr',
            'subject_ref' => 'person-42',
            'right' => 'access',
            'lawful_basis' => 'legal_obligation',
            'requested_at' => '2026-08-28T10:00:00Z',
        ]);

        $this->assertSame('open', $request->status);
        $this->assertSame('2026-09-27', $request->due_at->toDateString());
        $closed = $service->closePrivacyRequest($request, 'evidence://hr/export/person-42', str_repeat('a', 64));
        $this->assertSame('closed', $closed->status);
        $this->assertNotNull($closed->completed_at);
        $this->assertSame('evidence://hr/export/person-42', $closed->evidence_ref);
        $this->expectException(InvalidArgumentException::class);
        $service->closePrivacyRequest($closed, 'evidence://replacement-not-allowed', str_repeat('b', 64));
    }

    public function test_unregistered_processor_is_not_approved_for_production_data(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(DataGovernanceControlService::class)->registerProcessor([
            'tenant_id' => 'tenant-1', 'source' => 'office', 'name' => 'AI Provider',
            'purpose' => 'Document assistance', 'data_categories' => ['document_content'],
            'processing_countries' => ['US'], 'transfer_mechanism' => null,
            'agreement_owner' => 'privacy@example.test', 'review_due_at' => '2027-08-28',
            'agreement_evidence_ref' => 'evidence://processor/ai-provider', 'agreement_evidence_sha256' => str_repeat('c', 64),
        ]);
    }

    public function test_legal_hold_blocks_disposition_until_release(): void
    {
        $service = app(DataGovernanceControlService::class);
        $policy = $service->defineRetentionPolicy([
            'tenant_id' => 'tenant-1', 'source' => 'finance', 'record_class' => 'journal',
            'retention_days' => 3650, 'disposition_action' => 'delete',
        ]);
        $hold = $service->placeLegalHold($policy, 'Litigation 2026');

        $this->assertFalse($service->mayDispose($policy));
        try {
            $service->recordDisposition($policy, [
                'record_ref' => 'journal-42', 'action' => 'delete', 'record_created_at' => now()->subYears(11),
                'evidence_ref' => 'evidence://finance/journal-42/deleted',
                'evidence_sha256' => str_repeat('d', 64),
            ]);
            $this->fail('A legal hold must prevent a disposition receipt.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('disposition_receipts', 0);
        }
        $service->releaseLegalHold($hold);
        $this->assertTrue($service->mayDispose($policy));
        $receipt = $service->recordDisposition($policy, [
            'record_ref' => 'journal-42', 'action' => 'delete', 'record_created_at' => now()->subYears(11),
            'evidence_ref' => 'evidence://finance/journal-42/deleted',
            'evidence_sha256' => str_repeat('d', 64),
        ]);
        $this->assertSame('delete', $receipt->action);
        $this->assertNotNull($receipt->disposed_at);
        $retry = $service->recordDisposition($policy, [
            'record_ref' => 'journal-42', 'action' => 'delete', 'record_created_at' => now()->subYears(11),
            'evidence_ref' => 'evidence://finance/journal-42/deleted',
            'evidence_sha256' => str_repeat('d', 64),
        ]);
        $this->assertSame($receipt->id, $retry->id);
        $this->assertDatabaseCount('disposition_receipts', 1);

        $this->expectException(InvalidArgumentException::class);
        $service->recordDisposition($policy, [
            'record_ref' => 'journal-42', 'action' => 'delete', 'record_created_at' => now()->subYears(11),
            'evidence_ref' => 'evidence://finance/journal-42/different',
            'evidence_sha256' => str_repeat('e', 64),
        ]);
    }

    public function test_recovery_control_requires_a_successful_recent_restore_drill(): void
    {
        $service = app(DataGovernanceControlService::class);
        $service->recordRecoveryEvidence([
            'tenant_id' => 'tenant-1', 'source' => 'docflow', 'kind' => 'restore_drill',
            'occurred_at' => now()->subDays(10), 'outcome' => 'successful',
            'evidence_ref' => 'evidence://restore/docflow/2026-q3',
            'evidence_sha256' => str_repeat('e', 64),
        ]);

        $evidence = RecoveryEvidence::query()->where('source', 'docflow')->firstOrFail();
        app(GovernanceControlReviewService::class)->review([
            'resource_type' => 'recovery_evidence', 'resource_id' => $evidence->id,
            'decision' => 'approved', 'review_evidence_ref' => 'evidence://review/restore/docflow',
            'review_evidence_sha256' => str_repeat('1', 64),
        ], User::factory()->create());

        $this->assertTrue($service->hasCurrentRecoveryEvidence('tenant-1', 'docflow', now()));
        $this->assertFalse($service->hasCurrentRecoveryEvidence('tenant-1', 'office', now()));
        $service->recordRecoveryEvidence([
            'tenant_id' => 'tenant-1', 'source' => 'office', 'kind' => 'restore_drill',
            'occurred_at' => now()->addDay(), 'outcome' => 'successful', 'evidence_ref' => 'evidence://future',
            'evidence_sha256' => str_repeat('f', 64),
        ]);
        $this->assertFalse($service->hasCurrentRecoveryEvidence('tenant-1', 'office', now()));
    }
}
