<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('third_party_collaboration_request_cancellations')) {
            Schema::create('third_party_collaboration_request_cancellations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('third_party_engagement_collaboration_request_id')->unique('tp_collab_cancel_request_unique')->constrained('third_party_engagement_collaboration_requests', indexName: 'tp_collab_cancel_request_fk')->restrictOnDelete();
                $table->foreignId('latest_event_id')->constrained('third_party_engagement_collaboration_events', indexName: 'tp_collab_cancel_event_fk')->restrictOnDelete();
                $table->json('request_snapshot');
                $table->json('latest_event_snapshot');
                $table->json('recipient_context');
                $table->json('due_context');
                $table->text('reason');
                $table->foreignId('cancelled_by')->constrained('users', indexName: 'tp_collab_cancel_actor_fk')->restrictOnDelete();
                $table->json('actor_snapshot');
                $table->timestamp('cancelled_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Cancellation evidence is retained during routine code rollback.
    }
};
