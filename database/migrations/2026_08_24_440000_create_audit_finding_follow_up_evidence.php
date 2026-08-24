<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('audit_finding_follow_ups', 'evidence_manifest')) {
            Schema::table('audit_finding_follow_ups', function (Blueprint $table): void {
                $table->json('evidence_manifest')->nullable()->after('evidence_reference');
            });
        }
        if (! Schema::hasTable('audit_finding_follow_up_evidence')) {
            Schema::create('audit_finding_follow_up_evidence', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('audit_finding_follow_up_id')->constrained('audit_finding_follow_ups')->restrictOnDelete();
                $table->foreignId('file_attachment_id')->constrained('file_attachments')->restrictOnDelete();
                $table->foreignId('data_request_response_id_snapshot')->constrained('data_request_responses')->restrictOnDelete();
                $table->string('response_status_snapshot');
                $table->foreignId('data_request_id_snapshot')->constrained('data_requests')->restrictOnDelete();
                $table->foreignId('audit_id_snapshot')->constrained('audits')->restrictOnDelete();
                $table->foreignId('linked_by')->constrained('users')->restrictOnDelete();
                $table->string('disk_snapshot');
                $table->string('file_name_snapshot');
                $table->string('file_path_snapshot', 1000);
                $table->unsignedBigInteger('file_size_snapshot');
                $table->char('sha256', 64);
                $table->timestamp('linked_at');
                $table->timestamps();
                $table->unique(['audit_finding_follow_up_id', 'file_attachment_id'], 'audit_finding_follow_up_evidence_unique');
            });
        }
    }

    public function down(): void
    {
        // Governed follow-up evidence is retained during routine rollback.
    }
};
