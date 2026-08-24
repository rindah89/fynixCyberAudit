<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('policy_acknowledgement_deliveries')) {
            return;
        }

        Schema::create('policy_acknowledgement_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_acknowledgement_assignment_id')->unique('policy_ack_delivery_assignment_unique')
                ->constrained('policy_acknowledgement_assignments', indexName: 'policy_ack_delivery_assignment_fk')->restrictOnDelete();
            $table->foreignId('policy_acknowledgement_campaign_id')
                ->constrained('policy_acknowledgement_campaigns', indexName: 'policy_ack_delivery_campaign_fk')->restrictOnDelete();
            $table->foreignId('user_id')->constrained(indexName: 'policy_ack_delivery_user_fk')->restrictOnDelete();
            $table->string('channel');
            $table->uuid('notification_id')->unique();
            $table->json('recipient_snapshot');
            $table->json('campaign_snapshot');
            $table->timestamp('attempted_at');
            $table->timestamp('delivered_at');
            $table->char('fingerprint', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Governed notification-delivery evidence is retained during routine code rollback.
    }
};
