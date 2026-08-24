<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('third_party_engagement_collaboration_escalation_actions')) {
            return;
        }

        Schema::create('third_party_engagement_collaboration_escalation_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('third_party_engagement_collaboration_escalation_id')
                ->constrained('third_party_engagement_collaboration_escalations', indexName: 'tp_collab_esc_action_escalation_fk')->restrictOnDelete();
            $table->unsignedTinyInteger('version');
            $table->string('status');
            $table->text('summary');
            $table->text('action_plan')->nullable();
            $table->date('target_resolution_at')->nullable();
            $table->foreignId('actor_id')->constrained('users', indexName: 'tp_collab_esc_action_actor_fk')->restrictOnDelete();
            $table->json('actor_snapshot');
            $table->json('escalation_snapshot');
            $table->json('accepted_event_snapshot')->nullable();
            $table->timestamp('recorded_at');
            $table->char('fingerprint', 64)->unique();
            $table->timestamps();
            $table->unique(['third_party_engagement_collaboration_escalation_id', 'version'], 'tp_collab_esc_action_version_unique');
            $table->unique(['third_party_engagement_collaboration_escalation_id', 'status'], 'tp_collab_esc_action_status_unique');
        });
    }

    public function down(): void
    {
        // Governed escalation lifecycle evidence is retained during routine code rollback.
    }
};
