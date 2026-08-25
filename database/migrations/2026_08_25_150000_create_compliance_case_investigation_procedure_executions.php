<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_case_investigation_procedure_executions')) {
            Schema::create('compliance_case_investigation_procedure_executions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_id')->constrained(indexName: 'cc_proc_exec_case_fk')->restrictOnDelete();
                $table->foreignId('compliance_case_investigation_plan_id')->constrained('compliance_case_investigation_plans', indexName: 'cc_proc_exec_plan_fk')->restrictOnDelete();
                $table->unsignedTinyInteger('procedure_index');
                $table->text('procedure_text');
                $table->string('result');
                $table->longText('summary');
                $table->longText('findings')->nullable();
                $table->text('source_reference')->nullable();
                $table->foreignId('executed_by')->constrained('users', indexName: 'cc_proc_exec_actor_fk')->restrictOnDelete();
                $table->json('executor_snapshot');
                $table->json('plan_snapshot');
                $table->json('case_snapshot');
                $table->timestamp('executed_at');
                $table->char('fingerprint', 64)->unique('cc_proc_exec_fingerprint_uq');
                $table->timestamps();
                $table->unique(['compliance_case_investigation_plan_id', 'procedure_index'], 'cc_proc_exec_plan_index_uq');
            });
        }
    }

    public function down(): void
    { /* Governed investigation procedure evidence is retained on routine rollback. */
    }
};
