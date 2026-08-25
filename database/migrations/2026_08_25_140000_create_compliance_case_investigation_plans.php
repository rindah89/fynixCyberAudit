<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('compliance_cases', 'investigation_planning_governed_at')) {
            Schema::table('compliance_cases', fn (Blueprint $table) => $table->timestamp('investigation_planning_governed_at')->nullable()->after('governed_at'));
        }
        if (! Schema::hasTable('compliance_case_investigation_plans')) {
            Schema::create('compliance_case_investigation_plans', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_id')->constrained(indexName: 'cc_plan_case_fk')->restrictOnDelete();
                $table->unsignedTinyInteger('version');
                $table->json('objectives');
                $table->longText('scope');
                $table->json('procedures');
                $table->date('target_completion_at');
                $table->foreignId('authored_by')->constrained('users', indexName: 'cc_plan_author_fk')->restrictOnDelete();
                $table->json('author_snapshot');
                $table->json('case_snapshot');
                $table->longText('rationale');
                $table->timestamp('submitted_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['compliance_case_id', 'version'], 'cc_plan_version_uq');
            });
        }
        if (! Schema::hasTable('compliance_case_investigation_plan_reviews')) {
            Schema::create('compliance_case_investigation_plan_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_investigation_plan_id')->constrained('compliance_case_investigation_plans', indexName: 'cc_plan_review_plan_fk')->restrictOnDelete();
                $table->unique('compliance_case_investigation_plan_id', 'cc_plan_review_plan_uq');
                $table->string('decision');
                $table->longText('summary');
                $table->foreignId('reviewed_by')->constrained('users', indexName: 'cc_plan_reviewer_fk')->restrictOnDelete();
                $table->json('reviewer_snapshot');
                $table->json('plan_snapshot');
                $table->timestamp('reviewed_at');
                $table->char('fingerprint', 64)->unique('cc_plan_review_fingerprint_uq');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    { /* Governed planning evidence is retained on routine rollback. */
    }
};
