<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('policy_acknowledgement_campaigns', 'knowledge_check_snapshot')) {
            Schema::table('policy_acknowledgement_campaigns', function (Blueprint $table): void {
                $table->json('knowledge_check_snapshot')->nullable()->after('policy_fingerprint');
                $table->char('knowledge_check_fingerprint', 64)->nullable()->after('knowledge_check_snapshot');
            });
        }
        if (! Schema::hasTable('policy_acknowledgement_knowledge_check_attempts')) {
            Schema::create('policy_acknowledgement_knowledge_check_attempts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('policy_acknowledgement_assignment_id')->constrained('policy_acknowledgement_assignments', indexName: 'policy_ack_check_attempt_assignment_fk')->restrictOnDelete();
                $table->foreignId('policy_acknowledgement_campaign_id')->constrained('policy_acknowledgement_campaigns', indexName: 'policy_ack_check_attempt_campaign_fk')->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->foreignId('submitted_by')->constrained('users', indexName: 'policy_ack_check_attempt_submitter_fk')->restrictOnDelete();
                $table->json('answers_snapshot');
                $table->json('question_snapshot');
                $table->unsignedTinyInteger('score_percentage');
                $table->boolean('passed');
                $table->timestamp('submitted_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['policy_acknowledgement_assignment_id', 'version'], 'policy_ack_check_attempt_version_unique');
            });
        }
    }

    public function down(): void
    {
        // Governed comprehension-check evidence is retained during routine code rollback.
    }
};
