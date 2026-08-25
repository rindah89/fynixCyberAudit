<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('compliance_case_investigation_procedure_executions', 'version')) {
            Schema::table('compliance_case_investigation_procedure_executions', function (Blueprint $table): void {
                $table->unsignedSmallInteger('version')->default(1)->after('procedure_index');
            });
        }
        if (! Schema::hasColumn('compliance_case_investigation_procedure_executions', 'fingerprint_version')) {
            Schema::table('compliance_case_investigation_procedure_executions', function (Blueprint $table): void {
                $table->string('fingerprint_version', 32)->default('procedure-execution/v1')->after('version');
            });
        }
        if (Schema::hasIndex('compliance_case_investigation_procedure_executions', 'cc_proc_exec_plan_index_uq')) {
            Schema::table('compliance_case_investigation_procedure_executions', fn (Blueprint $table) => $table->dropUnique('cc_proc_exec_plan_index_uq'));
        }
        if (! Schema::hasIndex('compliance_case_investigation_procedure_executions', 'cc_proc_exec_plan_idx_ver_uq')) {
            Schema::table('compliance_case_investigation_procedure_executions', function (Blueprint $table): void {
                $table->unique(['compliance_case_investigation_plan_id', 'procedure_index', 'version'], 'cc_proc_exec_plan_idx_ver_uq');
            });
        }
        if (! Schema::hasTable('compliance_case_investigation_procedure_reviews')) {
            Schema::create('compliance_case_investigation_procedure_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_investigation_procedure_execution_id')->unique('cc_proc_review_execution_uq')
                    ->constrained('compliance_case_investigation_procedure_executions', indexName: 'cc_proc_review_execution_fk')->restrictOnDelete();
                $table->string('decision');
                $table->longText('summary');
                $table->foreignId('reviewed_by')->constrained('users', indexName: 'cc_proc_review_actor_fk')->restrictOnDelete();
                $table->json('reviewer_snapshot');
                $table->json('execution_snapshot');
                $table->timestamp('reviewed_at');
                $table->char('fingerprint', 64)->unique('cc_proc_review_fingerprint_uq');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    { /* Governed supervisory review and execution-version evidence is retained on routine rollback. */
    }
};
