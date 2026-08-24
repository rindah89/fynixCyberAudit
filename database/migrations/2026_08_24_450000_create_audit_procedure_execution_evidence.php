<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('audit_procedure_executions', 'evidence_manifest')) {
            Schema::table('audit_procedure_executions', function (Blueprint $table): void {
                $table->json('evidence_manifest')->nullable()->after('evidence_reference');
            });
        }
        if (! Schema::hasTable('audit_procedure_execution_evidence')) {
            Schema::create('audit_procedure_execution_evidence', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('audit_procedure_execution_id')->constrained('audit_procedure_executions')->restrictOnDelete();
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
                $table->unique(['audit_procedure_execution_id', 'file_attachment_id'], 'audit_procedure_execution_evidence_unique');
            });
        }
    }

    public function down(): void
    {
        // Governed workpaper evidence is retained during routine rollback.
    }
};
