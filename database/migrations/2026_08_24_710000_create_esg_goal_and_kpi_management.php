<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('esg_goals')) {
            Schema::create('esg_goals', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('esg_material_topic_id')->constrained('esg_material_topics', indexName: 'esg_goal_topic_fk')->restrictOnDelete();
                $table->string('code', 30)->unique();
                $table->string('title');
                $table->text('description');
                $table->foreignId('owner_id')->constrained('users', indexName: 'esg_goal_owner_fk')->restrictOnDelete();
                $table->string('status', 30);
                $table->date('baseline_date');
                $table->date('target_date');
                $table->json('topic_snapshot');
                $table->json('assessment_snapshot');
                $table->foreignId('created_by')->constrained('users', indexName: 'esg_goal_creator_fk')->restrictOnDelete();
                $table->dateTime('governed_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->index(['esg_material_topic_id', 'status']);
            });
        }

        if (! Schema::hasTable('esg_kpis')) {
            Schema::create('esg_kpis', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('esg_goal_id')->constrained('esg_goals', indexName: 'esg_kpi_goal_fk')->restrictOnDelete();
                $table->string('code', 30)->unique();
                $table->string('name');
                $table->text('description');
                $table->foreignId('owner_id')->constrained('users', indexName: 'esg_kpi_owner_fk')->restrictOnDelete();
                $table->string('unit', 100);
                $table->string('direction', 20);
                $table->decimal('baseline_value', 20, 6);
                $table->decimal('target_value', 20, 6);
                $table->text('measurement_method');
                $table->text('source_reference')->nullable();
                $table->unsignedSmallInteger('frequency_days');
                $table->dateTime('next_due_at');
                $table->dateTime('last_observed_at')->nullable();
                $table->string('last_status', 30)->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('goal_snapshot');
                $table->foreignId('created_by')->constrained('users', indexName: 'esg_kpi_creator_fk')->restrictOnDelete();
                $table->dateTime('governed_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->index(['esg_goal_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('esg_kpi_observations')) {
            Schema::create('esg_kpi_observations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('esg_kpi_id')->constrained('esg_kpis', indexName: 'esg_obs_kpi_fk')->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->json('kpi_snapshot');
                $table->decimal('observed_value', 20, 6);
                $table->string('status', 30);
                $table->text('reason');
                $table->text('notes')->nullable();
                $table->text('source_reference')->nullable();
                $table->foreignId('observed_by')->constrained('users', indexName: 'esg_obs_actor_fk')->restrictOnDelete();
                $table->dateTime('observed_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['esg_kpi_id', 'version'], 'esg_obs_version_unique');
            });
        }
    }

    public function down(): void
    {
        // ESG goal and performance history is retained during routine rollback.
    }
};
