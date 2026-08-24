<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('incident_lessons')) {
            Schema::create('incident_lessons', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('incident_id')->constrained(indexName: 'incident_lesson_incident_fk')->restrictOnDelete();
                $table->string('area');
                $table->text('observation');
                $table->text('recommendation');
                $table->foreignId('owner_id')->constrained('users', indexName: 'incident_lesson_owner_fk')->restrictOnDelete();
                $table->date('target_date')->nullable();
                $table->string('status');
                $table->timestamp('governed_at');
                $table->timestamps();
                $table->index(['incident_id', 'id']);
            });
        }

        if (! Schema::hasTable('incident_lesson_events')) {
            Schema::create('incident_lesson_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('incident_id')->constrained(indexName: 'incident_lesson_event_incident_fk')->restrictOnDelete();
                $table->foreignId('incident_lesson_id')->constrained(indexName: 'incident_lesson_event_lesson_fk')->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->string('event_type');
                $table->json('before_snapshot')->nullable();
                $table->json('after_snapshot');
                $table->text('rationale');
                $table->foreignId('recorded_by')->constrained('users', indexName: 'incident_lesson_event_actor_fk')->restrictOnDelete();
                $table->timestamp('recorded_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['incident_lesson_id', 'version'], 'incident_lesson_event_version_unique');
                $table->index(['incident_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        // Governed lessons-learned history is retained on routine rollback.
    }
};
