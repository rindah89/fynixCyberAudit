<?php

namespace Tests\Feature;

use App\Models\DataProcessor;
use App\Models\ProcessorInventoryRun;
use App\Suite\DataGovernanceControlService;
use App\Suite\GovernanceOversightService;
use App\Suite\ProcessorInventoryReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ProcessorInventoryReconcilerTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciles_complete_inventory_and_preserves_unchanged_review(): void
    {
        $this->configure(['finance' => [$this->entry('Hosting', 'a')], 'hr' => [$this->entry('Payroll', 'b')]], ['finance', 'hr']);
        $result = app(ProcessorInventoryReconciler::class)->reconcile();
        $this->assertSame(['sources' => 2, 'active' => 2, 'retired' => 0], $result);
        $this->assertDatabaseHas('processor_inventory_runs', ['status' => 'successful', 'source_count' => 2, 'active_count' => 2]);
        $this->assertSame('current', app(GovernanceOversightService::class)->report()['processor_inventory_reconciliation']);

        $processor = DataProcessor::where('source', 'finance')->firstOrFail();
        $processor->update(['status' => 'approved', 'review_digest' => str_repeat('c', 64)]);
        app(ProcessorInventoryReconciler::class)->reconcile();
        $this->assertSame('approved', $processor->fresh()->status);

        $inventory = config('data_governance.processor_inventory');
        $inventory['finance'][0]['agreement_evidence_sha256'] = str_repeat('d', 64);
        config(['data_governance.processor_inventory' => $inventory]);
        app(ProcessorInventoryReconciler::class)->reconcile();
        $this->assertSame('pending_review', $processor->fresh()->status);
        $this->assertNull($processor->fresh()->review_digest);
    }

    public function test_retires_entries_removed_from_the_declared_inventory(): void
    {
        $this->configure(['finance' => [$this->entry('Hosting', 'a'), $this->entry('Email', 'b')]]);
        app(ProcessorInventoryReconciler::class)->reconcile();
        $this->configure(['finance' => [$this->entry('Hosting', 'a')]]);

        $result = app(ProcessorInventoryReconciler::class)->reconcile();

        $this->assertSame(1, $result['retired']);
        $this->assertFalse(DataProcessor::where('name', 'Email')->firstOrFail()->active);
        $oversight = app(GovernanceOversightService::class)->report();
        $this->assertSame(1, $oversight['sources']['finance']['operability']['pending_processor_reviews']);
    }

    public function test_fails_closed_when_required_source_is_missing(): void
    {
        $this->configure(['finance' => [$this->entry('Hosting', 'a')]], ['finance', 'hr']);
        try {
            app(ProcessorInventoryReconciler::class)->reconcile();
            $this->fail('Incomplete inventory must fail.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseHas('processor_inventory_runs', ['status' => 'failed', 'error_code' => 'validation_failed']);
            $this->assertSame('missing_failed_or_stale', app(GovernanceOversightService::class)->report()['processor_inventory_reconciliation']);
        }
    }

    public function test_rejects_duplicate_processor_names(): void
    {
        $this->configure(['finance' => [$this->entry('Hosting', 'a'), $this->entry('Hosting', 'b')]]);
        $this->expectException(InvalidArgumentException::class);
        app(ProcessorInventoryReconciler::class)->reconcile();
    }

    public function test_latest_failed_or_stale_run_invalidates_a_certified_register(): void
    {
        ProcessorInventoryRun::create(['status' => 'successful', 'source_count' => 1, 'active_count' => 1, 'completed_at' => now()->subDays(2)]);
        $this->assertFalse(app(DataGovernanceControlService::class)->hasCurrentProcessorRegister('tenant-1', 'finance', now()));

        ProcessorInventoryRun::create(['status' => 'failed', 'error_code' => 'validation_failed', 'completed_at' => now()]);
        $this->assertFalse(app(DataGovernanceControlService::class)->hasCurrentProcessorRegister('tenant-1', 'finance', now()));
    }

    private function configure(array $inventory, array $sources = ['finance']): void
    {
        $bindings = [];
        foreach ($sources as $source) {
            $bindings[$source] = ['enabled' => true, 'tenant_id' => 'tenant-1'];
        }
        config([
            'data_governance.required_sources' => $sources,
            'data_governance.bindings' => $bindings,
            'data_governance.processor_inventory' => $inventory,
        ]);
    }

    private function entry(string $name, string $digest): array
    {
        return [
            'name' => $name, 'purpose' => 'Business service',
            'data_categories' => ['account', 'contact'], 'processing_countries' => ['CM'],
            'transfer_mechanism' => 'contract', 'agreement_owner' => 'privacy-office',
            'agreement_evidence_ref' => 'evidence://processor/'.strtolower($name),
            'agreement_evidence_sha256' => str_repeat($digest, 64), 'review_due_at' => '2027-08-28',
        ];
    }
}
