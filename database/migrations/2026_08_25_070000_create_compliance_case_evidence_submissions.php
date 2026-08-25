<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_case_evidence_submissions')) {
            Schema::create('compliance_case_evidence_submissions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_id')->constrained(indexName: 'cc_evidence_case_fk')->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->text('summary');
                $table->json('case_snapshot');
                $table->json('latest_event_snapshot');
                $table->json('evidence_manifest');
                $table->foreignId('recorded_by')->constrained('users', indexName: 'cc_evidence_actor_fk')->restrictOnDelete();
                $table->json('actor_snapshot');
                $table->timestamp('recorded_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['compliance_case_id', 'version'], 'cc_evidence_case_version_uq');
            });
        }

        if (! Schema::hasTable('compliance_case_evidence_files')) {
            Schema::create('compliance_case_evidence_files', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_evidence_submission_id')->constrained(indexName: 'cc_evidence_file_submission_fk')->restrictOnDelete();
                $table->foreignId('file_attachment_id')->constrained(indexName: 'cc_evidence_file_attachment_fk')->restrictOnDelete();
                $table->unsignedBigInteger('data_request_response_id_snapshot');
                $table->string('response_status_snapshot', 32);
                $table->unsignedBigInteger('data_request_id_snapshot');
                $table->unsignedBigInteger('audit_id_snapshot');
                $table->foreignId('linked_by')->constrained('users', indexName: 'cc_evidence_file_actor_fk')->restrictOnDelete();
                $table->string('disk_snapshot', 64);
                $table->string('file_name_snapshot');
                $table->string('file_path_snapshot', 2048);
                $table->unsignedBigInteger('file_size_snapshot');
                $table->char('sha256', 64);
                $table->timestamp('linked_at');
                $table->timestamps();
                $table->unique(['compliance_case_evidence_submission_id', 'file_attachment_id'], 'cc_evidence_file_source_uq');
            });
        }
    }

    public function down(): void
    {
        // Governed compliance-case evidence remains retained during routine code rollback.
    }
};
