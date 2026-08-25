<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('third_party_collaboration_closure_acknowledgement_deliveries')) {
            return;
        }

        Schema::create('third_party_collaboration_closure_acknowledgement_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('third_party_collaboration_closure_acknowledgement_id')
                ->constrained('third_party_collaboration_closure_acknowledgements', indexName: 'tp_collab_ack_del_ack_fk')->restrictOnDelete();
            $table->foreignId('third_party_collaboration_request_closure_id')
                ->constrained('third_party_collaboration_request_closures', indexName: 'tp_collab_ack_del_close_fk')->restrictOnDelete();
            $table->foreignId('third_party_engagement_collaboration_request_id')
                ->constrained('third_party_engagement_collaboration_requests', indexName: 'tp_collab_ack_del_req_fk')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users', indexName: 'tp_collab_ack_del_user_fk')->restrictOnDelete();
            $table->json('accountability_roles');
            $table->json('recipient_snapshot');
            $table->json('acknowledgement_snapshot');
            $table->string('channel', 32);
            $table->uuid('notification_id')->unique();
            $table->timestamp('attempted_at');
            $table->timestamp('delivered_at');
            $table->char('fingerprint', 64)->unique();
            $table->timestamps();
            $table->unique(['third_party_collaboration_closure_acknowledgement_id', 'user_id'], 'tp_collab_ack_del_recipient_uq');
        });
    }

    public function down(): void
    {
        // Governed acknowledgement-delivery evidence remains retained during routine code rollback.
    }
};
