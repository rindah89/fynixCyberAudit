<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('third_party_engagement_collaboration_reminders')) {
            return;
        }

        Schema::create('third_party_engagement_collaboration_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('third_party_engagement_collaboration_request_id')
                ->constrained('third_party_engagement_collaboration_requests', indexName: 'tp_collab_reminder_request_fk')->restrictOnDelete();
            $table->foreignId('third_party_engagement_id')
                ->constrained('third_party_engagements', indexName: 'tp_collab_reminder_engagement_fk')->restrictOnDelete();
            $table->foreignId('vendor_user_id')
                ->constrained('vendor_users', indexName: 'tp_collab_reminder_recipient_fk')->restrictOnDelete();
            $table->string('type');
            $table->string('channel');
            $table->uuid('notification_id')->unique();
            $table->json('recipient_snapshot');
            $table->json('request_snapshot');
            $table->json('event_snapshot');
            $table->timestamp('attempted_at');
            $table->timestamp('delivered_at');
            $table->char('fingerprint', 64)->unique();
            $table->timestamps();
            $table->unique(['third_party_engagement_collaboration_request_id', 'type'], 'tp_collab_reminder_request_type_unique');
        });
    }

    public function down(): void
    {
        // Governed reminder-delivery evidence is retained during routine code rollback.
    }
};
