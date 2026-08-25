<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('third_party_collaboration_request_closures')) {
            Schema::create('third_party_collaboration_request_closures', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('third_party_engagement_collaboration_request_id')->constrained('third_party_engagement_collaboration_requests', indexName: 'tp_collab_close_request_fk')->restrictOnDelete();
                $table->foreignId('accepted_event_id')->constrained('third_party_engagement_collaboration_events', indexName: 'tp_collab_close_event_fk')->restrictOnDelete();
                $table->json('request_snapshot');
                $table->json('accepted_event_snapshot');
                $table->json('recipient_context');
                $table->json('due_context');
                $table->json('escalation_snapshot')->nullable();
                $table->text('summary');
                $table->foreignId('closed_by')->constrained('users', indexName: 'tp_collab_close_actor_fk')->restrictOnDelete();
                $table->json('actor_snapshot');
                $table->timestamp('closed_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique('third_party_engagement_collaboration_request_id', 'tp_collab_close_request_unique');
                $table->unique('fingerprint', 'tp_collab_close_fingerprint_unique');
            });
        }
    }

    public function down(): void
    {
        // Collaboration closure evidence is retained during routine code rollback.
    }
};
