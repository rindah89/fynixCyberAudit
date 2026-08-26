<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_case_access_grants')) {
            Schema::create('compliance_case_access_grants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_id')->constrained('compliance_cases', indexName: 'cc_grant_case_fk')->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->foreignId('grantee_id')->constrained('users', indexName: 'cc_grant_grantee_fk')->restrictOnDelete();
                $table->json('grantee_snapshot');
                $table->longText('purpose');
                $table->timestamp('starts_at');
                $table->timestamp('ends_at');
                $table->foreignId('granted_by')->constrained('users', indexName: 'cc_grant_actor_fk')->restrictOnDelete();
                $table->json('grantor_snapshot');
                $table->timestamp('granted_at');
                $table->char('fingerprint', 64)->unique('cc_grant_fingerprint_uq');
                $table->timestamps();
                $table->unique(['compliance_case_id', 'version'], 'cc_grant_case_version_uq');
            });
        }
        if (! Schema::hasTable('compliance_case_access_grant_revocations')) {
            Schema::create('compliance_case_access_grant_revocations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_access_grant_id')->unique('cc_grant_rev_grant_uq')
                    ->constrained('compliance_case_access_grants', indexName: 'cc_grant_rev_grant_fk')->restrictOnDelete();
                $table->longText('summary');
                $table->foreignId('revoked_by')->constrained('users', indexName: 'cc_grant_rev_actor_fk')->restrictOnDelete();
                $table->json('revoker_snapshot');
                $table->json('grant_snapshot');
                $table->timestamp('revoked_at');
                $table->char('fingerprint', 64)->unique('cc_grant_rev_fingerprint_uq');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Governed access-grant evidence is retained on routine rollback.
    }
};
