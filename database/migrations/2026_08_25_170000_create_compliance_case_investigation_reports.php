<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('compliance_cases', 'investigation_reporting_governed_at')) {
            Schema::table('compliance_cases', function (Blueprint $table): void {
                $table->timestamp('investigation_reporting_governed_at')->nullable()->after('investigation_planning_governed_at');
            });
        }
        if (! Schema::hasTable('compliance_case_investigation_reports')) {
            Schema::create('compliance_case_investigation_reports', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_id')->constrained(indexName: 'cc_inv_report_case_fk')->restrictOnDelete();
                $table->unsignedTinyInteger('version');
                $table->string('outcome');
                $table->longText('executive_summary');
                $table->longText('analysis');
                $table->longText('findings');
                $table->longText('recommendations');
                $table->json('report_snapshot');
                $table->foreignId('authored_by')->constrained('users', indexName: 'cc_inv_report_author_fk')->restrictOnDelete();
                $table->json('author_snapshot');
                $table->timestamp('authored_at');
                $table->char('fingerprint', 64)->unique('cc_inv_report_fingerprint_uq');
                $table->timestamps();
                $table->unique(['compliance_case_id', 'version'], 'cc_inv_report_case_version_uq');
            });
        }
        if (! Schema::hasTable('compliance_case_investigation_report_reviews')) {
            Schema::create('compliance_case_investigation_report_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_investigation_report_id')->unique('cc_inv_report_review_report_uq')
                    ->constrained('compliance_case_investigation_reports', indexName: 'cc_inv_report_review_report_fk')->restrictOnDelete();
                $table->string('decision');
                $table->longText('summary');
                $table->foreignId('reviewed_by')->constrained('users', indexName: 'cc_inv_report_review_actor_fk')->restrictOnDelete();
                $table->json('reviewer_snapshot');
                $table->json('report_snapshot');
                $table->timestamp('reviewed_at');
                $table->char('fingerprint', 64)->unique('cc_inv_report_review_fingerprint_uq');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    { /* Governed investigation report and review evidence is retained on routine rollback. */
    }
};
