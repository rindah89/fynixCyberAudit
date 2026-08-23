<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('policy_attestation_evidence')) {
            return;
        }

        Schema::create('policy_attestation_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_attestation_id')->constrained()->restrictOnDelete();
            $table->foreignId('file_attachment_id')->constrained()->restrictOnDelete();
            $table->foreignId('data_request_response_id_snapshot')->constrained('data_request_responses')->restrictOnDelete();
            $table->string('response_status_snapshot');
            $table->foreignId('data_request_id_snapshot')->constrained('data_requests')->restrictOnDelete();
            $table->foreignId('audit_id_snapshot')->constrained('audits')->restrictOnDelete();
            $table->foreignId('linked_by')->constrained('users')->restrictOnDelete();
            $table->string('disk_snapshot');
            $table->string('file_name_snapshot');
            $table->string('file_path_snapshot', 1024);
            $table->unsignedBigInteger('file_size_snapshot');
            $table->char('sha256', 64);
            $table->timestamp('linked_at');
            $table->timestamps();
            $table->unique(['policy_attestation_id', 'file_attachment_id'], 'policy_attestation_evidence_unique');
        });
    }

    public function down(): void
    {
        // Governed policy-attestation evidence is retained during routine rollback.
    }
};
