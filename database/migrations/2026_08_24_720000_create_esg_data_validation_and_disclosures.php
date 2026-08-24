<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('esg_data_validations')) {
            Schema::create('esg_data_validations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('esg_kpi_observation_id')->constrained('esg_kpi_observations', indexName: 'esg_validation_obs_fk')->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->json('observation_snapshot');
                $table->text('completeness_assessment');
                $table->text('accuracy_assessment');
                $table->text('consistency_assessment');
                $table->text('evidence_reference')->nullable();
                $table->string('outcome', 30);
                $table->text('summary');
                $table->foreignId('validated_by')->constrained('users', indexName: 'esg_validation_actor_fk')->restrictOnDelete();
                $table->dateTime('validated_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['esg_kpi_observation_id', 'version'], 'esg_validation_version_unique');
            });
        }

        if (! Schema::hasTable('esg_disclosures')) {
            Schema::create('esg_disclosures', function (Blueprint $table): void {
                $table->id();
                $table->string('disclosure_key', 100);
                $table->string('code', 140)->unique();
                $table->unsignedSmallInteger('version');
                $table->string('title');
                $table->date('reporting_period_start');
                $table->date('reporting_period_end');
                $table->json('framework_references');
                $table->text('narrative');
                $table->json('validation_snapshot');
                $table->foreignId('prepared_by')->constrained('users', indexName: 'esg_disclosure_preparer_fk')->restrictOnDelete();
                $table->dateTime('prepared_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['disclosure_key', 'version'], 'esg_disclosure_version_unique');
            });
        }

        if (! Schema::hasTable('esg_disclosure_decisions')) {
            Schema::create('esg_disclosure_decisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('esg_disclosure_id')->constrained('esg_disclosures', indexName: 'esg_disclosure_decision_fk')->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->json('disclosure_snapshot');
                $table->string('decision', 30);
                $table->text('rationale');
                $table->foreignId('decided_by')->constrained('users', indexName: 'esg_disclosure_decider_fk')->restrictOnDelete();
                $table->dateTime('decided_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['esg_disclosure_id', 'version'], 'esg_disclosure_decision_version_unique');
            });
        }

        if (! Schema::hasTable('esg_disclosure_validation')) {
            Schema::create('esg_disclosure_validation', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('esg_disclosure_id')->constrained('esg_disclosures', indexName: 'esg_disc_validation_disc_fk')->restrictOnDelete();
                $table->foreignId('esg_data_validation_id')->constrained('esg_data_validations', indexName: 'esg_disc_validation_data_fk')->restrictOnDelete();
                $table->timestamps();
                $table->unique(['esg_disclosure_id', 'esg_data_validation_id'], 'esg_disc_validation_unique');
            });
        }
    }

    public function down(): void
    {
        // ESG validation and disclosure evidence is retained during routine rollback.
    }
};
