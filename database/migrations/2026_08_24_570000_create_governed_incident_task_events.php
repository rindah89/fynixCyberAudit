<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('incident_tasks', 'governed_at')) {
            Schema::table('incident_tasks', function (Blueprint $table) {
                $table->timestamp('governed_at')->nullable()->after('due_date');
            });
        }

        if (! Schema::hasTable('incident_task_events')) {
            Schema::create('incident_task_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('incident_id')->constrained()->restrictOnDelete();
                $table->foreignId('incident_task_id')->constrained()->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->string('event_type');
                $table->string('from_status')->nullable();
                $table->string('to_status');
                $table->json('before_snapshot')->nullable();
                $table->json('after_snapshot');
                $table->text('summary');
                $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('recorded_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['incident_task_id', 'version'], 'incident_task_event_version_unique');
                $table->index(['incident_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        // Governed incident-task history is retained on routine rollback.
    }
};
