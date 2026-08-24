<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('incident_notifications')) {
            Schema::create('incident_notifications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('incident_id')->constrained(indexName: 'incident_notification_incident_fk')->restrictOnDelete();
                $table->string('audience');
                $table->string('framework')->nullable();
                $table->string('recipient');
                $table->string('status');
                $table->timestamp('deadline_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->text('delivery_reference')->nullable();
                $table->timestamp('governed_at');
                $table->timestamps();
                $table->index(['incident_id', 'id']);
            });
        }

        if (! Schema::hasTable('incident_notification_events')) {
            Schema::create('incident_notification_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('incident_id')->constrained(indexName: 'incident_notification_event_incident_fk')->restrictOnDelete();
                $table->foreignId('incident_notification_id')->constrained(indexName: 'incident_notification_event_notification_fk')->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->string('event_type');
                $table->json('before_snapshot')->nullable();
                $table->json('after_snapshot');
                $table->text('rationale');
                $table->foreignId('recorded_by')->constrained('users', indexName: 'incident_notification_event_actor_fk')->restrictOnDelete();
                $table->timestamp('recorded_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['incident_notification_id', 'version'], 'incident_notification_event_version_unique');
                $table->index(['incident_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        // Governed notification decisions are retained on routine rollback.
    }
};
