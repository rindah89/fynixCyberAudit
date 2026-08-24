<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_case_mutexes')) {
            Schema::create('compliance_case_mutexes', function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->timestamps();
            });
        }
        DB::table('compliance_case_mutexes')->insertOrIgnore(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        if (! Schema::hasTable('compliance_cases')) {
            Schema::create('compliance_cases', function (Blueprint $table): void {
                $table->id();
                $table->string('number', 30)->unique();
                $table->string('title');
                $table->string('category', 40);
                $table->string('priority', 20);
                $table->string('status', 30);
                $table->text('allegation');
                $table->string('source_channel', 100)->nullable();
                $table->text('source_reference')->nullable();
                $table->string('reporter_reference')->nullable();
                $table->boolean('confidential')->default(true);
                $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
                $table->dateTime('due_at')->nullable();
                $table->text('triage_summary')->nullable();
                $table->text('investigation_summary')->nullable();
                $table->text('resolution_summary')->nullable();
                $table->text('closure_summary')->nullable();
                $table->dateTime('opened_at');
                $table->dateTime('resolved_at')->nullable();
                $table->dateTime('closed_at')->nullable();
                $table->dateTime('governed_at');
                $table->timestamps();
                $table->index(['status', 'priority']);
                $table->index(['assigned_to', 'status']);
            });
        }

        if (! Schema::hasTable('compliance_case_events')) {
            Schema::create('compliance_case_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_id')->constrained('compliance_cases')->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->string('event_type', 40);
                $table->json('before_snapshot')->nullable();
                $table->json('after_snapshot');
                $table->text('summary');
                $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
                $table->dateTime('recorded_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['compliance_case_id', 'version'], 'compliance_case_event_version_unique');
            });
        }
    }

    public function down(): void
    {
        // Governed case and event history is retained during routine rollback.
    }
};
