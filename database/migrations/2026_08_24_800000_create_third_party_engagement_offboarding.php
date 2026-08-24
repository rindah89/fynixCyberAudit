<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('third_party_engagements', 'offboarding_readiness_snapshot')) {
            Schema::table('third_party_engagements', fn (Blueprint $table) => $table->json('offboarding_readiness_snapshot')->nullable());
        }
        if (! Schema::hasTable('third_party_engagement_offboarding_requirements')) {
            Schema::create('third_party_engagement_offboarding_requirements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('third_party_engagement_id')->constrained(indexName: 'tp_offboard_req_engagement_fk')->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->string('category', 30);
                $table->string('title');
                $table->text('acceptance_criteria');
                $table->foreignId('owner_id')->constrained('users', indexName: 'tp_offboard_req_owner_fk')->restrictOnDelete();
                $table->date('due_at');
                $table->boolean('required');
                $table->json('engagement_snapshot');
                $table->foreignId('defined_by')->constrained('users', indexName: 'tp_offboard_req_definer_fk')->restrictOnDelete();
                $table->timestamp('defined_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['third_party_engagement_id', 'version'], 'tp_offboard_req_version_unique');
            });
        }
        if (! Schema::hasTable('third_party_engagement_offboarding_completions')) {
            Schema::create('third_party_engagement_offboarding_completions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('third_party_engagement_offboarding_requirement_id')->constrained(indexName: 'tp_offboard_completion_req_fk')->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->text('completion_summary');
                $table->string('source_reference')->nullable();
                $table->json('requirement_snapshot');
                $table->foreignId('completed_by')->constrained('users', indexName: 'tp_offboard_completion_actor_fk')->restrictOnDelete();
                $table->timestamp('completed_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['third_party_engagement_offboarding_requirement_id', 'version'], 'tp_offboard_completion_version_unique');
            });
        }
        if (! Schema::hasTable('third_party_engagement_offboarding_readiness_reviews')) {
            Schema::create('third_party_engagement_offboarding_readiness_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('third_party_engagement_id')->constrained(indexName: 'tp_offboard_review_engagement_fk')->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->string('decision', 30);
                $table->text('conditions')->nullable();
                $table->text('summary');
                $table->json('engagement_snapshot');
                $table->json('requirements_snapshot');
                $table->char('engagement_event_fingerprint', 64);
                $table->foreignId('reviewed_by')->constrained('users', indexName: 'tp_offboard_review_actor_fk')->restrictOnDelete();
                $table->timestamp('reviewed_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['third_party_engagement_id', 'version'], 'tp_offboard_review_version_unique');
            });
        }
    }

    public function down(): void
    { /* Governed offboarding evidence is retained during routine rollback. */
    }
};
