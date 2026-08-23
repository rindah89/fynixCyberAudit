<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('governance_issue_closure_evidence')) {
            return;
        }

        Schema::create('governance_issue_closure_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('governance_issue_lifecycle_id')->constrained()->restrictOnDelete();
            $table->foreignId('governance_issue_transition_id')->constrained()->restrictOnDelete();
            $table->foreignId('file_attachment_id')->constrained()->restrictOnDelete();
            $table->foreignId('data_request_response_id_snapshot')->constrained('data_request_responses')->restrictOnDelete();
            $table->string('response_status_snapshot');
            $table->foreignId('data_request_id_snapshot')->constrained('data_requests')->restrictOnDelete();
            $table->foreignId('audit_id_snapshot')->constrained('audits')->restrictOnDelete();
            $table->foreignId('linked_by')->constrained('users')->restrictOnDelete();
            $table->string('disk_snapshot');
            $table->string('file_name_snapshot');
            $table->string('file_path_snapshot');
            $table->unsignedBigInteger('file_size_snapshot');
            $table->char('sha256', 64);
            $table->timestamp('linked_at');
            $table->timestamps();
            $table->unique(['governance_issue_transition_id', 'file_attachment_id'], 'governance_closure_evidence_unique');
        });
    }

    public function down(): void
    {
        // Governed closure evidence is retained during routine code rollback.
    }
};
