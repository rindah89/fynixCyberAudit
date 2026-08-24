<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('incident_phase_transitions', 'evidence_manifest')) {
            Schema::table('incident_phase_transitions', function (Blueprint $table) {
                $table->json('evidence_manifest')->nullable()->after('incident_snapshot');
            });
        }

        if (! Schema::hasTable('incident_phase_transition_evidence')) {
            Schema::create('incident_phase_transition_evidence', function (Blueprint $table) {
                $table->id();
                $table->foreignId('incident_phase_transition_id');
                $table->foreign('incident_phase_transition_id', 'ipt_evidence_transition_fk')->references('id')->on('incident_phase_transitions')->restrictOnDelete();
                $table->foreignId('file_attachment_id');
                $table->foreign('file_attachment_id', 'ipt_evidence_attachment_fk')->references('id')->on('file_attachments')->restrictOnDelete();
                $table->foreignId('data_request_response_id_snapshot');
                $table->foreign('data_request_response_id_snapshot', 'ipt_evidence_response_fk')->references('id')->on('data_request_responses')->restrictOnDelete();
                $table->string('response_status_snapshot');
                $table->foreignId('data_request_id_snapshot');
                $table->foreign('data_request_id_snapshot', 'ipt_evidence_request_fk')->references('id')->on('data_requests')->restrictOnDelete();
                $table->foreignId('audit_id_snapshot');
                $table->foreign('audit_id_snapshot', 'ipt_evidence_audit_fk')->references('id')->on('audits')->restrictOnDelete();
                $table->foreignId('linked_by');
                $table->foreign('linked_by', 'ipt_evidence_actor_fk')->references('id')->on('users')->restrictOnDelete();
                $table->string('disk_snapshot');
                $table->string('file_name_snapshot');
                $table->string('file_path_snapshot');
                $table->unsignedBigInteger('file_size_snapshot');
                $table->char('sha256', 64);
                $table->timestamp('linked_at');
                $table->timestamps();
                $table->unique(['incident_phase_transition_id', 'file_attachment_id'], 'ipt_evidence_file_unique');
            });
        }
    }

    public function down(): void
    {
        // Governed retained incident evidence remains available after routine rollback.
    }
};
