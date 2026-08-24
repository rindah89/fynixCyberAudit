<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_effort_budgets')) {
            Schema::create('audit_effort_budgets', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('audit_id')->constrained()->restrictOnDelete();
                $table->foreignId('audit_procedure_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->unsignedInteger('planned_minutes');
                $table->text('rationale');
                $table->json('allocation_snapshot');
                $table->foreignId('set_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('set_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['audit_id', 'audit_procedure_id', 'user_id', 'version'], 'audit_effort_budget_version_unique');
            });
        }

        if (! Schema::hasTable('audit_time_entries')) {
            Schema::create('audit_time_entries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('audit_id')->constrained()->restrictOnDelete();
                $table->foreignId('audit_procedure_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->string('entry_type', 20);
                $table->foreignId('reverses_time_entry_id')->nullable()->unique()->constrained('audit_time_entries')->restrictOnDelete();
                $table->date('work_date');
                $table->unsignedSmallInteger('minutes');
                $table->string('activity', 255);
                $table->text('notes')->nullable();
                $table->string('source_reference', 2000)->nullable();
                $table->json('budget_snapshot')->nullable();
                $table->json('procedure_snapshot')->nullable();
                $table->foreignId('entered_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('entered_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('audit_closeout_submissions') && ! Schema::hasColumn('audit_closeout_submissions', 'audit_effort_snapshots')) {
            Schema::table('audit_closeout_submissions', fn (Blueprint $table) => $table->json('audit_effort_snapshots')->nullable()->after('audit_procedure_snapshots'));
        }
    }

    public function down(): void
    {
        // Retain governed effort and closeout evidence during routine rollback.
    }
};
