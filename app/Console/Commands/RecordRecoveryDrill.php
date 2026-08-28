<?php

namespace App\Console\Commands;

use App\Suite\DataGovernanceControlService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RecordRecoveryDrill extends Command
{
    protected $signature = 'fynix:record-recovery-drill {report : Absolute path to the restore-drill JSON report}';

    protected $description = 'Validate a completed CyberAudit restore drill and queue digest-bound evidence for independent review';

    public function handle(DataGovernanceControlService $controls): int
    {
        $path = (string) $this->argument('report');
        if (! str_starts_with($path, '/') || ! is_file($path) || is_link($path)) {
            throw new InvalidArgumentException('Recovery report must be an existing absolute regular file.');
        }
        $raw = file_get_contents($path);
        if ($raw === false || strlen($raw) > 1024 * 1024) {
            throw new InvalidArgumentException('Recovery report is unreadable or exceeds 1 MiB.');
        }
        $report = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $required = [
            'schema', 'source', 'occurred_at', 'backup_sha256', 'database_restored',
            'migrations_verified', 'storage_verified', 'application_preflight_passed',
            'restore_seconds', 'restored_table_count',
        ];
        if (! is_array($report) || array_keys($report) !== $required
            || $report['schema'] !== 'fynix.cyberaudit.restore-drill.v1'
            || $report['source'] !== 'cyberaudit'
            || ! preg_match('/^[a-f0-9]{64}$/', (string) $report['backup_sha256'])
            || $report['database_restored'] !== true
            || $report['migrations_verified'] !== true
            || $report['storage_verified'] !== true
            || $report['application_preflight_passed'] !== true
            || ! is_int($report['restore_seconds']) || $report['restore_seconds'] < 0 || $report['restore_seconds'] > 7200
            || ! is_int($report['restored_table_count']) || $report['restored_table_count'] < 1) {
            throw new InvalidArgumentException('Recovery report does not prove a complete successful restore drill.');
        }
        $occurredAt = CarbonImmutable::parse((string) $report['occurred_at'])->utc();
        if ($occurredAt->isFuture() || $occurredAt->lt(now()->utc()->subDay())) {
            throw new InvalidArgumentException('Recovery report must be recorded within 24 hours of the drill.');
        }
        $tenantId = (string) config('data_governance.publisher.tenant_id');
        if ($tenantId === '') {
            throw new InvalidArgumentException('Governance tenant binding is required.');
        }
        $digest = hash('sha256', $raw);
        $evidence = $controls->recordRecoveryEvidence([
            'tenant_id' => $tenantId,
            'source' => 'cyberaudit',
            'kind' => 'restore_drill',
            'occurred_at' => $occurredAt,
            'outcome' => 'successful',
            'evidence_ref' => 'evidence://cyberaudit/recovery/'.$digest,
            'evidence_sha256' => $digest,
        ]);
        $this->line(json_encode([
            'outcome' => 'recorded',
            'resource_type' => 'recovery_evidence',
            'resource_id' => $evidence->getKey(),
            'evidence_sha256' => $digest,
            'review_status' => $evidence->review_status,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
