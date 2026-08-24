<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('esg_material_topic_mutexes')) {
            Schema::create('esg_material_topic_mutexes', function (Blueprint $t): void {
                $t->unsignedTinyInteger('id')->primary();
                $t->timestamps();
            });
        } DB::table('esg_material_topic_mutexes')->insertOrIgnore(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        if (! Schema::hasTable('esg_material_topics')) {
            Schema::create('esg_material_topics', function (Blueprint $t): void {
                $t->id();
                $t->string('code', 30)->unique();
                $t->string('name');
                $t->string('pillar', 30);
                $t->string('status', 30);
                $t->foreignId('owner_id')->constrained('users', indexName: 'esg_topic_owner_fk')->restrictOnDelete();
                $t->text('description');
                $t->text('impact_context');
                $t->text('risk_context');
                $t->text('opportunity_context');
                $t->json('stakeholder_groups');
                $t->json('framework_references');
                $t->text('organizational_boundary');
                $t->text('source_reference')->nullable();
                $t->date('next_review_at');
                $t->dateTime('governed_at');
                $t->timestamps();
                $t->index(['pillar', 'status']);
            });
        } if (! Schema::hasTable('esg_material_topic_versions')) {
            Schema::create('esg_material_topic_versions', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('esg_material_topic_id')->constrained('esg_material_topics', indexName: 'esg_topic_version_topic_fk')->restrictOnDelete();
                $t->unsignedSmallInteger('version');
                $t->json('topic_snapshot');
                $t->text('change_summary');
                $t->foreignId('recorded_by')->constrained('users', indexName: 'esg_topic_version_actor_fk')->restrictOnDelete();
                $t->dateTime('recorded_at');
                $t->char('fingerprint', 64)->unique();
                $t->timestamps();
                $t->unique(['esg_material_topic_id', 'version'], 'esg_topic_version_unique');
            });
        } if (! Schema::hasTable('esg_materiality_assessments')) {
            Schema::create('esg_materiality_assessments', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('esg_material_topic_id')->constrained('esg_material_topics', indexName: 'esg_assessment_topic_fk')->restrictOnDelete();
                $t->unsignedSmallInteger('version');
                $t->foreignId('topic_version_id')->constrained('esg_material_topic_versions', indexName: 'esg_assessment_version_fk')->restrictOnDelete();
                $t->json('topic_snapshot');
                $t->unsignedTinyInteger('impact_materiality');
                $t->unsignedTinyInteger('financial_materiality');
                $t->text('stakeholder_evidence');
                $t->text('methodology');
                $t->string('decision', 30);
                $t->text('decision_summary');
                $t->foreignId('assessed_by')->constrained('users', indexName: 'esg_assessment_actor_fk')->restrictOnDelete();
                $t->dateTime('assessed_at');
                $t->date('next_review_at');
                $t->char('fingerprint', 64)->unique();
                $t->timestamps();
                $t->unique(['esg_material_topic_id', 'version'], 'esg_assessment_version_unique');
            });
        }
    }

    public function down(): void
    {/* ESG governance evidence is retained during routine rollback. */
    }
};
