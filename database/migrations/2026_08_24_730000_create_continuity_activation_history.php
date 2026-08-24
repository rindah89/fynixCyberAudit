<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('continuity_activations')) {
            Schema::create('continuity_activations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('recovery_plan_id')->constrained()->restrictOnDelete();
                $table->foreignId('business_service_id')->constrained()->restrictOnDelete();
                $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('activated_by')->constrained('users')->restrictOnDelete();
                $table->string('status');
                $table->text('disruption_summary');
                $table->text('business_impact');
                $table->timestamp('started_at');
                $table->timestamp('restored_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->unsignedInteger('actual_recovery_time_minutes')->nullable();
                $table->unsignedInteger('actual_recovery_point_minutes')->nullable();
                $table->string('outcome')->nullable();
                $table->json('service_snapshot');
                $table->json('plan_snapshot');
                $table->timestamps();
                $table->index(['business_service_id', 'started_at'], 'continuity_activation_service_started_idx');
            });
        }
        if (! Schema::hasTable('continuity_activation_events')) {
            Schema::create('continuity_activation_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('continuity_activation_id')->constrained()->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->string('from_status')->nullable();
                $table->string('to_status');
                $table->text('summary');
                $table->json('activation_snapshot');
                $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('recorded_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['continuity_activation_id', 'version'], 'continuity_activation_event_version_uq');
            });
        }
    }

    public function down(): void
    {
        // Retain disruption and recovery evidence during routine rollback.
    }
};
