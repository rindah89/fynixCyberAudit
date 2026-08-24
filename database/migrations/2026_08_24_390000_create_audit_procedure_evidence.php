<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_procedures')) {
            Schema::create('audit_procedures', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('audit_id')->constrained()->restrictOnDelete();
                $table->foreignId('audit_item_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->string('code', 50);
                $table->string('title');
                $table->text('objective');
                $table->text('steps');
                $table->string('method', 30);
                $table->text('population_description')->nullable();
                $table->unsignedInteger('planned_sample_size')->nullable();
                $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
                $table->date('due_at')->nullable();
                $table->string('status', 30)->default('planned');
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('created_at');
                $table->timestamp('updated_at');
                $table->unique(['audit_id', 'code', 'version']);
            });
        }

        if (! Schema::hasTable('audit_procedure_executions')) {
            Schema::create('audit_procedure_executions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('audit_procedure_id')->unique()->constrained()->restrictOnDelete();
                $table->string('outcome', 30);
                $table->text('result');
                $table->text('exceptions')->nullable();
                $table->unsignedInteger('sample_tested')->nullable();
                $table->string('evidence_reference', 2000)->nullable();
                $table->json('procedure_snapshot');
                $table->foreignId('executed_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('executed_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('audit_closeout_submissions') && ! Schema::hasColumn('audit_closeout_submissions', 'audit_procedure_snapshots')) {
            Schema::table('audit_closeout_submissions', fn (Blueprint $table) => $table->json('audit_procedure_snapshots')->nullable()->after('data_request_snapshots'));
        }
    }

    public function down(): void
    {
        // Retain governed audit procedure and execution evidence during routine rollback.
    }
};
