<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('third_party_collaboration_closure_acknowledgement_receipts')) {
            return;
        }

        Schema::create('third_party_collaboration_closure_acknowledgement_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('third_party_collaboration_closure_acknowledgement_delivery_id')
                ->unique('tp_collab_ack_receipt_delivery_uq')
                ->constrained('third_party_collaboration_closure_acknowledgement_deliveries', indexName: 'tp_collab_ack_receipt_delivery_fk')->restrictOnDelete();
            $table->foreignId('third_party_collaboration_closure_acknowledgement_id')
                ->constrained('third_party_collaboration_closure_acknowledgements', indexName: 'tp_collab_ack_receipt_ack_fk')->restrictOnDelete();
            $table->foreignId('third_party_engagement_collaboration_request_id')
                ->constrained('third_party_engagement_collaboration_requests', indexName: 'tp_collab_ack_receipt_req_fk')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users', indexName: 'tp_collab_ack_receipt_user_fk')->restrictOnDelete();
            $table->json('recipient_snapshot');
            $table->json('delivery_snapshot');
            $table->timestamp('acknowledged_at');
            $table->char('fingerprint', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Governed staff-receipt evidence remains retained during routine code rollback.
    }
};
