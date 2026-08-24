<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('policy_exception_monitoring_review_evidence')) {
            return;
        }

        Schema::create('policy_exception_monitoring_review_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_exception_monitoring_review_id')
                ->constrained(indexName: 'policy_exception_monitoring_evidence_review_fk')->restrictOnDelete();
            $table->foreignId('file_attachment_id')
                ->constrained(indexName: 'policy_exception_monitoring_evidence_file_fk')->restrictOnDelete();
            $table->foreignId('data_request_response_id_snapshot')
                ->constrained('data_request_responses', indexName: 'policy_exception_monitoring_evidence_response_fk')->restrictOnDelete();
            $table->string('response_status_snapshot');
            $table->foreignId('data_request_id_snapshot')
                ->constrained('data_requests', indexName: 'policy_exception_monitoring_evidence_request_fk')->restrictOnDelete();
            $table->foreignId('audit_id_snapshot')
                ->constrained('audits', indexName: 'policy_exception_monitoring_evidence_audit_fk')->restrictOnDelete();
            $table->foreignId('linked_by')
                ->constrained('users', indexName: 'policy_exception_monitoring_evidence_actor_fk')->restrictOnDelete();
            $table->string('disk_snapshot');
            $table->string('file_name_snapshot');
            $table->string('file_path_snapshot');
            $table->unsignedBigInteger('file_size_snapshot');
            $table->char('sha256', 64);
            $table->timestamp('linked_at');
            $table->timestamps();
            $table->unique(
                ['policy_exception_monitoring_review_id', 'file_attachment_id'],
                'policy_exception_monitoring_evidence_unique',
            );
        });
    }

    public function down(): void
    {
        // Governed policy-exception monitoring evidence is retained during routine code rollback.
    }
};
