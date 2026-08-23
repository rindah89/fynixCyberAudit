<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_risk_review_evidence')) {
            return;
        }

        Schema::create('vendor_risk_review_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_risk_review_id')->constrained()->restrictOnDelete();
            $table->foreignId('file_attachment_id')->constrained()->restrictOnDelete();
            $table->foreignId('data_request_response_id_snapshot')->constrained('data_request_responses')->restrictOnDelete();
            $table->foreignId('data_request_id_snapshot')->constrained('data_requests')->restrictOnDelete();
            $table->foreignId('audit_id_snapshot')->constrained('audits')->restrictOnDelete();
            $table->foreignId('linked_by')->constrained('users')->restrictOnDelete();
            $table->string('response_status_snapshot');
            $table->string('disk_snapshot');
            $table->string('file_path_snapshot', 1024);
            $table->string('file_name_snapshot');
            $table->unsignedBigInteger('file_size_snapshot');
            $table->string('sha256', 64);
            $table->timestamp('linked_at');
            $table->timestamps();
            $table->unique(['vendor_risk_review_id', 'file_attachment_id'], 'vendor_review_evidence_unique');
        });
    }

    public function down(): void
    {
        // Governed evidence history is retained during routine rollback.
    }
};
