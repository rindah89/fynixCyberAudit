<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_case_communication_decisions')) {
            Schema::create('compliance_case_communication_decisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_id')->constrained('compliance_cases', indexName: 'cc_comm_case_fk')->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->string('audience', 40);
                $table->longText('purpose');
                $table->string('decision', 20);
                $table->timestamp('deadline_at')->nullable();
                $table->string('external_reference')->nullable();
                $table->foreignId('decided_by')->constrained('users', indexName: 'cc_comm_actor_fk')->restrictOnDelete();
                $table->json('decider_snapshot');
                $table->json('case_snapshot');
                $table->timestamp('decided_at');
                $table->char('fingerprint', 64)->unique('cc_comm_fingerprint_uq');
                $table->timestamps();
                $table->unique(['compliance_case_id', 'version'], 'cc_comm_case_version_uq');
            });
        }
        if (! Schema::hasTable('compliance_case_reopen_proposals')) {
            Schema::create('compliance_case_reopen_proposals', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_id')->constrained('compliance_cases', indexName: 'cc_reopen_case_fk')->restrictOnDelete();
                $table->unsignedTinyInteger('version');
                $table->longText('summary');
                $table->foreignId('proposed_by')->constrained('users', indexName: 'cc_reopen_actor_fk')->restrictOnDelete();
                $table->json('proposer_snapshot');
                $table->json('case_snapshot');
                $table->timestamp('proposed_at');
                $table->char('fingerprint', 64)->unique('cc_reopen_fingerprint_uq');
                $table->timestamps();
                $table->unique(['compliance_case_id', 'version'], 'cc_reopen_case_version_uq');
            });
        }
        if (! Schema::hasTable('compliance_case_reopen_reviews')) {
            Schema::create('compliance_case_reopen_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_reopen_proposal_id')->unique('cc_reopen_review_proposal_uq')
                    ->constrained('compliance_case_reopen_proposals', indexName: 'cc_reopen_review_proposal_fk')->restrictOnDelete();
                $table->string('decision', 20);
                $table->longText('summary');
                $table->foreignId('reviewed_by')->constrained('users', indexName: 'cc_reopen_review_actor_fk')->restrictOnDelete();
                $table->json('reviewer_snapshot');
                $table->json('proposal_snapshot');
                $table->timestamp('reviewed_at');
                $table->char('fingerprint', 64)->unique('cc_reopen_review_fingerprint_uq');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Governed communication and reopen evidence is retained on routine rollback.
    }
};
