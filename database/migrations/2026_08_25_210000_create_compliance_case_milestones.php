<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_case_milestones')) {
            Schema::create('compliance_case_milestones', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_id')->constrained('compliance_cases', indexName: 'cc_ms_case_fk')->restrictOnDelete();
                $table->foreignId('compliance_case_event_id')->constrained('compliance_case_events', indexName: 'cc_ms_event_fk')->restrictOnDelete();
                $table->unsignedTinyInteger('version');
                $table->string('title');
                $table->longText('description');
                $table->foreignId('owner_id')->constrained('users', indexName: 'cc_ms_owner_fk')->restrictOnDelete();
                $table->json('owner_snapshot');
                $table->timestamp('due_at');
                $table->boolean('required')->default(true);
                $table->string('status', 20);
                $table->foreignId('defined_by')->constrained('users', indexName: 'cc_ms_actor_fk')->restrictOnDelete();
                $table->json('definer_snapshot');
                $table->json('case_snapshot');
                $table->timestamp('defined_at');
                $table->char('fingerprint', 64)->unique('cc_ms_fingerprint_uq');
                $table->timestamps();
                $table->unique(['compliance_case_id', 'version'], 'cc_ms_case_version_uq');
            });
        }
        if (! Schema::hasTable('compliance_case_milestone_events')) {
            Schema::create('compliance_case_milestone_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_milestone_id')->constrained('compliance_case_milestones', indexName: 'cc_ms_evt_ms_fk')->restrictOnDelete();
                $table->string('event_type', 30);
                $table->longText('summary')->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users', indexName: 'cc_ms_evt_actor_fk')->restrictOnDelete();
                $table->json('actor_snapshot')->nullable();
                $table->json('milestone_snapshot');
                $table->timestamp('recorded_at');
                $table->char('fingerprint', 64)->unique('cc_ms_evt_fingerprint_uq');
                $table->timestamps();
                $table->unique(['compliance_case_milestone_id', 'event_type'], 'cc_ms_evt_type_uq');
            });
        }
        if (! Schema::hasTable('compliance_case_milestone_deliveries')) {
            Schema::create('compliance_case_milestone_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_milestone_id')->constrained('compliance_case_milestones', indexName: 'cc_ms_del_ms_fk')->restrictOnDelete();
                $table->foreignId('compliance_case_milestone_event_id')->constrained('compliance_case_milestone_events', indexName: 'cc_ms_del_evt_fk')->restrictOnDelete();
                $table->foreignId('user_id')->constrained('users', indexName: 'cc_ms_del_user_fk')->restrictOnDelete();
                $table->string('event_type', 30);
                $table->string('channel', 30);
                $table->uuid('notification_id');
                $table->json('recipient_snapshot');
                $table->json('milestone_snapshot');
                $table->timestamp('attempted_at');
                $table->timestamp('delivered_at');
                $table->char('fingerprint', 64)->unique('cc_ms_del_fp_uq');
                $table->timestamps();
                $table->unique(['compliance_case_milestone_id', 'event_type'], 'cc_ms_del_type_uq');
                $table->unique('notification_id', 'cc_ms_del_notification_uq');
            });
        }
    }

    public function down(): void
    {
        // Governed milestone evidence is retained on routine rollback.
    }
};
