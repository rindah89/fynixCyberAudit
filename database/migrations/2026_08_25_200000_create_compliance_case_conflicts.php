<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_case_conflict_declarations')) {
            Schema::create('compliance_case_conflict_declarations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_id')->constrained('compliance_cases', indexName: 'cc_conflict_decl_case_fk')->restrictOnDelete();
                $table->foreignId('compliance_case_event_id')->constrained('compliance_case_events', indexName: 'cc_conflict_decl_event_fk')->restrictOnDelete();
                $table->unsignedTinyInteger('version');
                $table->foreignId('subject_user_id')->constrained('users', indexName: 'cc_conflict_decl_subject_fk')->restrictOnDelete();
                $table->json('subject_snapshot');
                $table->foreignId('declared_by')->constrained('users', indexName: 'cc_conflict_decl_actor_fk')->restrictOnDelete();
                $table->json('declarer_snapshot');
                $table->longText('nature');
                $table->longText('rationale');
                $table->json('case_snapshot');
                $table->json('latest_event_snapshot');
                $table->timestamp('declared_at');
                $table->char('fingerprint', 64)->unique('cc_conflict_decl_fingerprint_uq');
                $table->timestamps();
                $table->unique(['compliance_case_id', 'version'], 'cc_conflict_decl_case_version_uq');
            });
        }
        if (! Schema::hasTable('compliance_case_conflict_decisions')) {
            Schema::create('compliance_case_conflict_decisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_conflict_declaration_id')->unique('cc_conflict_decision_decl_uq')
                    ->constrained('compliance_case_conflict_declarations', indexName: 'cc_conflict_decision_decl_fk')->restrictOnDelete();
                $table->string('decision', 20);
                $table->longText('summary');
                $table->foreignId('decided_by')->constrained('users', indexName: 'cc_conflict_decision_actor_fk')->restrictOnDelete();
                $table->json('decider_snapshot');
                $table->json('declaration_snapshot');
                $table->timestamp('decided_at');
                $table->char('fingerprint', 64)->unique('cc_conflict_decision_fingerprint_uq');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Governed conflict and recusal evidence is retained on routine rollback.
    }
};
