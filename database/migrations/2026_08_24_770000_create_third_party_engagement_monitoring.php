<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('third_party_engagement_monitoring_indicators')) {
            Schema::create('third_party_engagement_monitoring_indicators', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('third_party_engagement_id')->constrained(indexName: 'tp_eng_monitor_indicator_engagement_fk')->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->string('code', 100);
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('category', 50);
                $table->string('unit', 50);
                $table->string('direction', 30);
                $table->decimal('warning_threshold', 21, 6);
                $table->decimal('critical_threshold', 21, 6);
                $table->unsignedSmallInteger('frequency_days');
                $table->foreignId('owner_id')->constrained('users', indexName: 'tp_eng_monitor_indicator_owner_fk')->restrictOnDelete();
                $table->text('measurement_method');
                $table->json('engagement_snapshot');
                $table->json('contract_review_snapshot');
                $table->json('risk_approval_snapshot');
                $table->foreignId('defined_by')->constrained('users', indexName: 'tp_eng_monitor_indicator_definer_fk')->restrictOnDelete();
                $table->timestamp('defined_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['third_party_engagement_id', 'code', 'version'], 'tp_eng_monitor_indicator_version_unique');
                $table->index(['third_party_engagement_id', 'code'], 'tp_eng_monitor_indicator_code_index');
            });
        }

        if (! Schema::hasTable('third_party_engagement_monitoring_observations')) {
            Schema::create('third_party_engagement_monitoring_observations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('third_party_engagement_monitoring_indicator_id')->constrained('third_party_engagement_monitoring_indicators', indexName: 'tp_eng_monitor_observation_indicator_fk')->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->decimal('observed_value', 21, 6);
                $table->string('status', 20);
                $table->text('reason');
                $table->text('notes')->nullable();
                $table->string('source_reference')->nullable();
                $table->json('indicator_snapshot');
                $table->json('engagement_snapshot');
                $table->json('contract_review_snapshot');
                $table->json('risk_approval_snapshot');
                $table->foreignId('observed_by')->constrained('users', indexName: 'tp_eng_monitor_observation_actor_fk')->restrictOnDelete();
                $table->timestamp('observed_at');
                $table->timestamp('recorded_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['third_party_engagement_monitoring_indicator_id', 'version'], 'tp_eng_monitor_observation_version_unique');
            });
        }
    }

    public function down(): void
    {
        // Governed engagement monitoring definitions and observations are retained during routine rollback.
    }
};
