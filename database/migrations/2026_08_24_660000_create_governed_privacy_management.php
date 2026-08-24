<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('privacy_activity_mutexes')) {
            Schema::create('privacy_activity_mutexes', function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->timestamps();
            });
        }
        DB::table('privacy_activity_mutexes')->insertOrIgnore(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        if (! Schema::hasTable('privacy_processing_activities')) {
            Schema::create('privacy_processing_activities', function (Blueprint $table): void {
                $table->id();
                $table->string('number', 30)->unique();
                $table->string('name');
                $table->string('status', 20);
                $table->foreignId('owner_id')->constrained('users', indexName: 'privacy_activity_owner_fk')->restrictOnDelete();
                $table->text('purpose');
                $table->string('lawful_basis', 255);
                $table->json('data_subject_categories');
                $table->json('personal_data_categories');
                $table->boolean('special_category_data')->default(false);
                $table->json('recipient_categories');
                $table->json('systems_and_vendors');
                $table->json('processing_locations');
                $table->boolean('cross_border_transfer')->default(false);
                $table->text('transfer_safeguards')->nullable();
                $table->string('retention_period', 255);
                $table->text('security_measures');
                $table->text('source_reference')->nullable();
                $table->date('next_review_at');
                $table->dateTime('governed_at');
                $table->timestamps();
                $table->index(['status', 'next_review_at']);
                $table->index(['owner_id', 'status']);
            });
        }
        if (! Schema::hasTable('privacy_activity_versions')) {
            Schema::create('privacy_activity_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('privacy_processing_activity_id')->constrained('privacy_processing_activities', indexName: 'privacy_activity_version_activity_fk')->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->json('activity_snapshot');
                $table->text('change_summary');
                $table->foreignId('recorded_by')->constrained('users', indexName: 'privacy_activity_version_actor_fk')->restrictOnDelete();
                $table->dateTime('recorded_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['privacy_processing_activity_id', 'version'], 'privacy_activity_version_unique');
            });
        }
        if (! Schema::hasTable('privacy_impact_assessments')) {
            Schema::create('privacy_impact_assessments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('privacy_processing_activity_id')->constrained('privacy_processing_activities', indexName: 'privacy_assessment_activity_fk')->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->foreignId('activity_version_id')->constrained('privacy_activity_versions', indexName: 'privacy_assessment_version_fk')->restrictOnDelete();
                $table->json('activity_snapshot');
                $table->text('necessity_assessment');
                $table->text('proportionality_assessment');
                $table->text('risk_summary');
                $table->json('mitigations');
                $table->string('residual_risk', 20);
                $table->string('decision', 30);
                $table->text('decision_summary');
                $table->foreignId('assessed_by')->constrained('users', indexName: 'privacy_assessment_actor_fk')->restrictOnDelete();
                $table->dateTime('assessed_at');
                $table->date('next_review_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['privacy_processing_activity_id', 'version'], 'privacy_impact_assessment_version_unique');
            });
        }
    }

    public function down(): void
    { /* Governed privacy history is retained during routine rollback. */
    }
};
