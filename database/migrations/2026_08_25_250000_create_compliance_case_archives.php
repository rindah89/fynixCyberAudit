<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_case_archive_manifests')) {
            Schema::create('compliance_case_archive_manifests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_id')->constrained('compliance_cases', indexName: 'cc_arch_case_fk')->restrictOnDelete();
                $table->foreignId('compliance_case_closure_report_id')->constrained('compliance_case_closure_reports', indexName: 'cc_arch_report_fk')->restrictOnDelete();
                $table->unsignedTinyInteger('version');
                $table->json('source_fingerprints');
                $table->string('archive_disk');
                $table->string('archive_path');
                $table->unsignedInteger('archive_size');
                $table->char('archive_sha256', 64);
                $table->string('schema_version', 40);
                $table->foreignId('generated_by')->constrained('users', indexName: 'cc_arch_actor_fk')->restrictOnDelete();
                $table->json('generator_snapshot');
                $table->timestamp('generated_at');
                $table->char('fingerprint', 64)->unique('cc_arch_fingerprint_uq');
                $table->timestamps();
                $table->unique(['compliance_case_id', 'version'], 'cc_arch_case_version_uq');
            });
        }
        if (! Schema::hasTable('compliance_case_archive_reviews')) {
            Schema::create('compliance_case_archive_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_archive_manifest_id')->unique('cc_arch_review_manifest_uq')
                    ->constrained('compliance_case_archive_manifests', indexName: 'cc_arch_review_manifest_fk')->restrictOnDelete();
                $table->string('decision', 20);
                $table->longText('summary');
                $table->foreignId('reviewed_by')->constrained('users', indexName: 'cc_arch_review_actor_fk')->restrictOnDelete();
                $table->json('reviewer_snapshot');
                $table->json('manifest_snapshot');
                $table->timestamp('reviewed_at');
                $table->char('fingerprint', 64)->unique('cc_arch_review_fingerprint_uq');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Governed archive evidence is retained on routine rollback.
    }
};
