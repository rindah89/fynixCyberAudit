<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('governed_model_mutexes')) {
            Schema::create('governed_model_mutexes', function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->timestamps();
            });
        }
        DB::table('governed_model_mutexes')->insertOrIgnore(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        if (! Schema::hasTable('governed_models')) {
            Schema::create('governed_models', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 30)->unique();
                $table->string('name');
                $table->string('model_type', 80);
                $table->unsignedTinyInteger('tier');
                $table->string('lifecycle_status', 30);
                $table->string('governance_status', 30);
                $table->foreignId('owner_id')->constrained('users', indexName: 'governed_model_owner_fk')->restrictOnDelete();
                $table->foreignId('developer_id')->constrained('users', indexName: 'governed_model_developer_fk')->restrictOnDelete();
                $table->text('intended_use');
                $table->text('methodology');
                $table->json('input_data');
                $table->json('outputs');
                $table->json('assumptions');
                $table->json('limitations');
                $table->json('usage_restrictions');
                $table->text('implementation_reference')->nullable();
                $table->string('change_frequency', 255);
                $table->date('next_review_at');
                $table->dateTime('governed_at');
                $table->timestamps();
                $table->index(['governance_status', 'tier']);
                $table->index(['owner_id', 'lifecycle_status']);
            });
        }
        if (! Schema::hasTable('governed_model_versions')) {
            Schema::create('governed_model_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('governed_model_id')->constrained('governed_models', indexName: 'governed_model_version_model_fk')->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->json('model_snapshot');
                $table->text('change_summary');
                $table->foreignId('recorded_by')->constrained('users', indexName: 'governed_model_version_actor_fk')->restrictOnDelete();
                $table->dateTime('recorded_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['governed_model_id', 'version'], 'governed_model_version_unique');
            });
        }
        if (! Schema::hasTable('model_validation_reviews')) {
            Schema::create('model_validation_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('governed_model_id')->constrained('governed_models', indexName: 'model_validation_model_fk')->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->foreignId('model_version_id')->constrained('governed_model_versions', indexName: 'model_validation_version_fk')->restrictOnDelete();
                $table->json('model_snapshot');
                $table->text('scope');
                $table->text('testing_performed');
                $table->json('findings');
                $table->text('performance_summary');
                $table->text('limitations_assessment');
                $table->string('decision', 30);
                $table->json('conditions');
                $table->text('decision_summary');
                $table->foreignId('validated_by')->constrained('users', indexName: 'model_validation_actor_fk')->restrictOnDelete();
                $table->dateTime('validated_at');
                $table->date('valid_until');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['governed_model_id', 'version'], 'model_validation_review_version_unique');
            });
        }
    }

    public function down(): void
    { /* Governed model and validation evidence is retained during routine rollback. */
    }
};
