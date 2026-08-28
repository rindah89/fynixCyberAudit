<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DeploymentRecoveryContractTest extends TestCase
{
    public function test_recovery_scripts_encrypt_rehearse_and_record_evidence(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['backup.sh', 'restore.sh', 'rehearse-restore.sh', 'quarterly-recovery.sh', 'install-recovery-schedule.sh'] as $script) {
            $path = $root.'/deploy/'.$script;
            $this->assertFileExists($path);
            $this->assertTrue(is_executable($path), $script.' must remain executable');
        }

        $backup = file_get_contents($root.'/deploy/backup.sh');
        $restore = file_get_contents($root.'/deploy/restore.sh');
        $rehearsal = file_get_contents($root.'/deploy/rehearse-restore.sh');
        $this->assertStringContainsString('FYNIX_BACKUP_AGE_RECIPIENT', $backup);
        $this->assertStringContainsString('| age -r', $backup);
        $this->assertStringContainsString('FYNIX_BACKUP_S3_URI is required', $backup);
        $this->assertStringContainsString('age --decrypt', $restore);
        $this->assertStringContainsString('CREATE DATABASE', $rehearsal);
        $this->assertStringContainsString('fynix:record-recovery-drill', $rehearsal);
        $this->assertStringContainsString('DROP DATABASE IF EXISTS', $rehearsal);
    }

    public function test_hourly_backup_and_quarterly_drill_timers_are_installed(): void
    {
        $root = dirname(__DIR__, 2);
        $daily = file_get_contents($root.'/deploy/fynix-cyberaudit-backup.timer');
        $quarterly = file_get_contents($root.'/deploy/fynix-cyberaudit-recovery-drill.timer');
        $installer = file_get_contents($root.'/deploy/install-recovery-schedule.sh');

        $this->assertStringContainsString('OnCalendar=hourly', $daily);
        $this->assertStringContainsString('OnCalendar=*-01,04,07,10-01 03:30:00 UTC', $quarterly);
        $this->assertStringContainsString('systemctl enable --now', $installer);
    }
}
