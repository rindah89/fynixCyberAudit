<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_risk_scenarios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('root_risk_id')->constrained('risks')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('name');
            $table->text('narrative');
            $table->unsignedSmallInteger('horizon_months');
            $table->string('probability_band');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('risk_count');
            $table->unsignedInteger('baseline_score_sum');
            $table->unsignedInteger('stressed_score_sum');
            $table->integer('score_delta');
            $table->unsignedSmallInteger('stressed_score_maximum');
            $table->unsignedInteger('above_appetite_count');
            $table->json('stressed_band_counts');
            $table->json('hierarchy_snapshot');
            $table->char('hierarchy_fingerprint', 64);
            $table->timestamp('analyzed_at');
            $table->timestamps();
            $table->unique(['root_risk_id', 'version']);
        });

        Schema::create('enterprise_risk_scenario_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enterprise_risk_scenario_id')->constrained()->restrictOnDelete();
            $table->foreignId('risk_id')->constrained('risks')->restrictOnDelete();
            $table->string('risk_code_snapshot');
            $table->string('risk_name_snapshot');
            $table->unsignedBigInteger('parent_risk_id_snapshot')->nullable();
            $table->unsignedBigInteger('owner_id_snapshot')->nullable();
            $table->unsignedSmallInteger('appetite_threshold_snapshot');
            $table->unsignedTinyInteger('baseline_likelihood');
            $table->unsignedTinyInteger('baseline_impact');
            $table->unsignedSmallInteger('baseline_score');
            $table->tinyInteger('likelihood_shift');
            $table->tinyInteger('impact_shift');
            $table->unsignedTinyInteger('stressed_likelihood');
            $table->unsignedTinyInteger('stressed_impact');
            $table->unsignedSmallInteger('stressed_score');
            $table->text('rationale')->nullable();
            $table->timestamps();
            $table->unique(['enterprise_risk_scenario_id', 'risk_id'], 'scenario_risk_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_risk_scenario_items');
        Schema::dropIfExists('enterprise_risk_scenarios');
    }
};
