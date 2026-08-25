<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('third_party_collaboration_request_acknowledgements')) {
            Schema::create('third_party_collaboration_request_acknowledgements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('third_party_engagement_collaboration_request_id')->constrained('third_party_engagement_collaboration_requests', indexName: 'tp_collab_ack_request_fk')->restrictOnDelete();
                $table->foreignId('latest_event_id')->constrained('third_party_engagement_collaboration_events', indexName: 'tp_collab_ack_event_fk')->restrictOnDelete();
                $table->char('recipient_context_fingerprint', 64);
                $table->json('request_snapshot');
                $table->json('latest_event_snapshot');
                $table->json('recipient_context');
                $table->json('due_context');
                $table->foreignId('vendor_user_id')->constrained('vendor_users', indexName: 'tp_collab_ack_recipient_fk')->restrictOnDelete();
                $table->json('recipient_snapshot');
                $table->timestamp('acknowledged_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['third_party_engagement_collaboration_request_id', 'recipient_context_fingerprint'], 'tp_collab_ack_context_unique');
            });
        }
    }

    public function down(): void
    {
        // Provider acknowledgement evidence is retained during routine code rollback.
    }
};
