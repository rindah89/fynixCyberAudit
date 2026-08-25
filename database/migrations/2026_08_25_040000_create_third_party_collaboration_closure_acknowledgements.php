<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('third_party_collaboration_closure_acknowledgements')) {
            return;
        }

        Schema::create('third_party_collaboration_closure_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('third_party_collaboration_request_closure_id')->unique()
                ->constrained('third_party_collaboration_request_closures', indexName: 'tp_collab_close_ack_closure_fk')->restrictOnDelete();
            $table->foreignId('third_party_collaboration_closure_delivery_id')->unique()
                ->constrained('third_party_collaboration_closure_deliveries', indexName: 'tp_collab_close_ack_delivery_fk')->restrictOnDelete();
            $table->foreignId('third_party_engagement_collaboration_request_id')
                ->constrained('third_party_engagement_collaboration_requests', indexName: 'tp_collab_close_ack_request_fk')->restrictOnDelete();
            $table->foreignId('vendor_user_id')
                ->constrained('vendor_users', indexName: 'tp_collab_close_ack_recipient_fk')->restrictOnDelete();
            $table->json('recipient_snapshot');
            $table->json('closure_snapshot');
            $table->json('delivery_snapshot');
            $table->timestamp('acknowledged_at');
            $table->char('fingerprint', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Governed closure-acknowledgement evidence remains retained during routine code rollback.
    }
};
