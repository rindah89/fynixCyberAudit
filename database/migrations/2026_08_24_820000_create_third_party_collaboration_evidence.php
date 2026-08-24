<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('third_party_engagement_collaboration_events', 'evidence_manifest')) {
            Schema::table('third_party_engagement_collaboration_events', function (Blueprint $table): void {
                $table->json('evidence_manifest')->nullable()->after('request_snapshot');
            });
        }
        if (! Schema::hasTable('third_party_engagement_collaboration_evidence')) {
            Schema::create('third_party_engagement_collaboration_evidence', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('third_party_engagement_collaboration_event_id');
                $table->foreign('third_party_engagement_collaboration_event_id', 'tp_collab_ev_file_event_fk')->references('id')->on('third_party_engagement_collaboration_events')->restrictOnDelete();
                $table->foreignId('vendor_document_id');
                $table->foreign('vendor_document_id', 'tp_collab_ev_file_document_fk')->references('id')->on('vendor_documents')->restrictOnDelete();
                $table->foreignId('vendor_id_snapshot');
                $table->foreign('vendor_id_snapshot', 'tp_collab_ev_file_vendor_fk')->references('id')->on('vendors')->restrictOnDelete();
                $table->foreignId('linked_by_vendor_user_id');
                $table->foreign('linked_by_vendor_user_id', 'tp_collab_ev_file_actor_fk')->references('id')->on('vendor_users')->restrictOnDelete();
                $table->string('document_status_snapshot', 30);
                $table->string('disk_snapshot');
                $table->string('file_name_snapshot');
                $table->string('file_path_snapshot');
                $table->unsignedBigInteger('file_size_snapshot');
                $table->char('sha256', 64);
                $table->timestamp('linked_at');
                $table->timestamps();
                $table->unique(['third_party_engagement_collaboration_event_id', 'vendor_document_id'], 'tp_collab_ev_file_unique');
            });
        }
    }

    public function down(): void
    {
        // Governed provider evidence remains available after routine rollback.
    }
};
