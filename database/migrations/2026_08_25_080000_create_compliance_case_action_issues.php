<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('compliance_case_action_issues')) {
            return;
        }

        Schema::create('compliance_case_action_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('compliance_case_id')->constrained('compliance_cases', indexName: 'cc_action_issue_case_fk')->restrictOnDelete();
            $table->foreignId('compliance_case_event_id')->unique()->constrained('compliance_case_events', indexName: 'cc_action_issue_event_fk')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users', indexName: 'cc_action_issue_owner_fk')->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users', indexName: 'cc_action_issue_opener_fk')->restrictOnDelete();
            $table->string('title');
            $table->longText('description');
            $table->string('severity');
            $table->string('status')->default('open');
            $table->foreignId('remediation_task_id')->nullable()->constrained('remediation_tasks', indexName: 'cc_action_issue_task_fk')->nullOnDelete();
            $table->json('source_snapshot');
            $table->timestamp('opened_at');
            $table->char('fingerprint', 64)->unique();
            $table->timestamps();
            $table->index(['compliance_case_id', 'id'], 'cc_action_issue_case_history_idx');
        });
    }

    public function down(): void
    {
        // Governed action-issue and remediation-link evidence is retained during routine rollback.
    }
};
