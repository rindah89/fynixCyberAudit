<?php

namespace Tests\Feature;

use App\Models\RecoveryEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class RecordRecoveryDrillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_recent_report_is_queued_for_independent_review(): void
    {
        Carbon::setTestNow('2026-08-28T12:00:00Z');
        config()->set('data_governance.publisher.tenant_id', 'tenant-1');
        $path = $this->report(['occurred_at' => '2026-08-28T11:55:00Z']);

        $this->artisan('fynix:record-recovery-drill', ['report' => $path])
            ->assertSuccessful();

        $evidence = RecoveryEvidence::query()->sole();
        $this->assertSame('tenant-1', $evidence->tenant_id);
        $this->assertSame('cyberaudit', $evidence->source);
        $this->assertSame('pending_review', $evidence->review_status);
        $this->assertSame(hash_file('sha256', $path), $evidence->evidence_sha256);
        unlink($path);
    }

    public function test_incomplete_or_stale_report_is_rejected(): void
    {
        Carbon::setTestNow('2026-08-28T12:00:00Z');
        config()->set('data_governance.publisher.tenant_id', 'tenant-1');
        $path = $this->report([
            'occurred_at' => '2026-08-20T11:55:00Z',
            'application_preflight_passed' => false,
        ]);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->artisan('fynix:record-recovery-drill', ['report' => $path])->run();
        } finally {
            $this->assertDatabaseCount('recovery_evidence', 0);
            unlink($path);
        }
    }

    /** @param array<string, mixed> $overrides */
    private function report(array $overrides = []): string
    {
        $report = array_merge([
            'schema' => 'fynix.cyberaudit.restore-drill.v1',
            'source' => 'cyberaudit',
            'occurred_at' => '2026-08-28T11:55:00Z',
            'backup_sha256' => str_repeat('b', 64),
            'database_restored' => true,
            'migrations_verified' => true,
            'storage_verified' => true,
            'application_preflight_passed' => true,
            'restore_seconds' => 42,
            'restored_table_count' => 12,
        ], $overrides);
        $path = tempnam(sys_get_temp_dir(), 'cyberaudit-recovery-');
        file_put_contents($path, json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
