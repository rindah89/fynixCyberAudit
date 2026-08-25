<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_case_interviews')) {
            Schema::create('compliance_case_interviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_id')->constrained('compliance_cases', indexName: 'cc_int_case_fk')->restrictOnDelete();
                $table->foreignId('subject_user_id')->nullable()->constrained('users', indexName: 'cc_int_subject_fk')->restrictOnDelete();
                $table->string('subject_reference')->nullable();
                $table->foreignId('interviewer_id')->constrained('users', indexName: 'cc_int_interviewer_fk')->restrictOnDelete();
                $table->string('status');
                $table->timestamp('scheduled_at');
                $table->timestamp('conducted_at')->nullable();
                $table->string('location')->nullable();
                $table->text('purpose');
                $table->longText('summary')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->timestamps();
                $table->index(['compliance_case_id', 'id'], 'cc_int_case_history_idx');
            });
        }

        if (! Schema::hasTable('compliance_case_interview_events')) {
            Schema::create('compliance_case_interview_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_interview_id')->constrained('compliance_case_interviews', indexName: 'cc_int_evt_interview_fk')->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->string('event_type');
                $table->json('before_snapshot')->nullable();
                $table->json('after_snapshot');
                $table->longText('rationale');
                $table->foreignId('recorded_by')->constrained('users', indexName: 'cc_int_evt_actor_fk')->restrictOnDelete();
                $table->timestamp('recorded_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['compliance_case_interview_id', 'version'], 'cc_int_evt_version_uq');
            });
        }
    }

    public function down(): void
    {
        // Governed interview records and append-only event evidence are retained during routine rollback.
    }
};
