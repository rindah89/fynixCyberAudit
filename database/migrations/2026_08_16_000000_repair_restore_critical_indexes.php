<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL can retain partially-created tables after failed DDL migrations.
        // SQLite executes the original table definitions atomically and does not
        // support the MySQL-specific ALTER/INFORMATION_SCHEMA repair below.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->assertMaximumLength('suite_entity_links', 'local_type', 64);
        $this->assertMaximumLength('suite_entity_links', 'system', 32);
        $this->assertMaximumLength('suite_entity_links', 'entity_type', 64);
        $this->assertMaximumLength('suite_entity_links', 'entity_id', 255);
        $this->assertMaximumLength('suite_entity_links', 'relation', 64);
        DB::statement('ALTER TABLE suite_entity_links MODIFY local_type VARCHAR(64) NOT NULL, MODIFY `system` VARCHAR(32) NOT NULL, MODIFY entity_type VARCHAR(64) NOT NULL, MODIFY entity_id VARCHAR(255) NOT NULL, MODIFY relation VARCHAR(64) NOT NULL DEFAULT \'derived_from\'');
        $this->addIndexUnlessPresent(
            'suite_entity_links',
            'suite_links_unique',
            'ALTER TABLE suite_entity_links ADD UNIQUE KEY suite_links_unique (local_type, local_id, `system`, entity_type, entity_id, relation)'
        );

        $this->assertMaximumLength('suite_inbound_high_water', 'source', 64);
        $this->assertMaximumLength('suite_inbound_high_water', 'entity_type', 64);
        $this->assertMaximumLength('suite_inbound_high_water', 'entity_id', 255);
        DB::statement('ALTER TABLE suite_inbound_high_water MODIFY source VARCHAR(64) NOT NULL, MODIFY entity_type VARCHAR(64) NOT NULL, MODIFY entity_id VARCHAR(255) NOT NULL');
        $this->addIndexUnlessPresent(
            'suite_inbound_high_water',
            'suite_high_water_unique',
            'ALTER TABLE suite_inbound_high_water ADD UNIQUE KEY suite_high_water_unique (local_tenant_id, source, entity_type, entity_id)'
        );

        $this->addIndexUnlessPresent(
            'remediation_project_members',
            'remediation_members_project_user_unique',
            'ALTER TABLE remediation_project_members ADD UNIQUE KEY remediation_members_project_user_unique (remediation_project_id, user_id)'
        );
    }

    public function down(): void
    {
        // Repair-only: dropping restored uniqueness would reintroduce corruption risk.
    }

    private function assertMaximumLength(string $table, string $column, int $maximum): void
    {
        $length = (int) DB::table($table)->max(DB::raw("CHAR_LENGTH(`{$column}`)"));
        if ($length > $maximum) {
            throw new \RuntimeException("{$table}.{$column} contains data longer than {$maximum} characters");
        }
    }

    private function addIndexUnlessPresent(string $table, string $index, string $statement): void
    {
        $exists = DB::table('information_schema.statistics')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
        if (! $exists) {
            DB::statement($statement);
        }
    }
};
