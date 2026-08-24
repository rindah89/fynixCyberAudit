<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('third_party_collaboration_escalation_issues')) {
            return;
        }

        Schema::create('third_party_collaboration_escalation_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('third_party_engagement_collaboration_escalation_id')->unique()
                ->constrained('third_party_engagement_collaboration_escalations', indexName: 'tp_collab_issue_escalation_fk')->restrictOnDelete();
            $table->foreignId('third_party_engagement_collaboration_escalation_action_id')->unique()
                ->constrained('third_party_engagement_collaboration_escalation_actions', indexName: 'tp_collab_issue_action_fk')->restrictOnDelete();
            $table->foreignId('third_party_engagement_id')->constrained('third_party_engagements', indexName: 'tp_collab_issue_engagement_fk')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users', indexName: 'tp_collab_issue_owner_fk')->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users', indexName: 'tp_collab_issue_opener_fk')->restrictOnDelete();
            $table->string('title');
            $table->longText('description');
            $table->string('severity')->default('high');
            $table->string('status')->default('open');
            $table->foreignId('remediation_task_id')->nullable()->constrained('remediation_tasks', indexName: 'tp_collab_issue_task_fk')->nullOnDelete();
            $table->json('source_snapshot');
            $table->timestamp('opened_at');
            $table->char('fingerprint', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Governed issue and remediation-link evidence is retained during routine code rollback.
    }
};
