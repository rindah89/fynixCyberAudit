<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('third_party_engagement_collaboration_escalations')) {
            return;
        }

        Schema::create('third_party_engagement_collaboration_escalations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('third_party_engagement_collaboration_request_id')->unique()
                ->constrained('third_party_engagement_collaboration_requests', indexName: 'tp_collab_escalation_request_fk')->restrictOnDelete();
            $table->foreignId('third_party_engagement_id')
                ->constrained('third_party_engagements', indexName: 'tp_collab_escalation_engagement_fk')->restrictOnDelete();
            $table->foreignId('vendor_user_id')
                ->constrained('vendor_users', indexName: 'tp_collab_escalation_vendor_user_fk')->restrictOnDelete();
            $table->string('channel');
            $table->json('notification_ids');
            $table->json('recipient_snapshots');
            $table->json('request_snapshot');
            $table->json('event_snapshot');
            $table->json('overdue_reminder_snapshot');
            $table->timestamp('attempted_at');
            $table->timestamp('delivered_at');
            $table->char('fingerprint', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Governed escalation-delivery evidence is retained during routine code rollback.
    }
};
