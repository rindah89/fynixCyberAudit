<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('third_party_collaboration_closure_deliveries')) {
            return;
        }

        Schema::create('third_party_collaboration_closure_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('third_party_collaboration_request_closure_id')->unique()
                ->constrained('third_party_collaboration_request_closures', indexName: 'tp_collab_close_delivery_closure_fk')->restrictOnDelete();
            $table->foreignId('third_party_engagement_collaboration_request_id')
                ->constrained('third_party_engagement_collaboration_requests', indexName: 'tp_collab_close_delivery_request_fk')->restrictOnDelete();
            $table->foreignId('vendor_user_id')
                ->constrained('vendor_users', indexName: 'tp_collab_close_delivery_recipient_fk')->restrictOnDelete();
            $table->string('channel', 30);
            $table->uuid('notification_id')->unique();
            $table->json('recipient_snapshot');
            $table->json('closure_snapshot');
            $table->timestamp('attempted_at');
            $table->timestamp('delivered_at');
            $table->char('fingerprint', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Governed closure-delivery evidence remains retained during routine code rollback.
    }
};
