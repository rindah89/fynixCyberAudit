<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('policy_acknowledgement_escalations')) {
            return;
        }
        Schema::create('policy_acknowledgement_escalations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_acknowledgement_assignment_id')->unique()
                ->constrained('policy_acknowledgement_assignments', indexName: 'policy_ack_escalation_assignment_fk')->restrictOnDelete();
            $table->foreignId('policy_acknowledgement_campaign_id')
                ->constrained('policy_acknowledgement_campaigns', indexName: 'policy_ack_escalation_campaign_fk')->restrictOnDelete();
            $table->foreignId('assigned_user_id')->constrained('users', indexName: 'policy_ack_escalation_assignee_fk')->restrictOnDelete();
            $table->foreignId('escalated_to_user_id')->constrained('users', indexName: 'policy_ack_escalation_recipient_fk')->restrictOnDelete();
            $table->string('channel');
            $table->uuid('notification_id')->unique();
            $table->json('assignment_snapshot');
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
        // Governed escalation-delivery evidence is retained during routine code rollback.
    }
};
