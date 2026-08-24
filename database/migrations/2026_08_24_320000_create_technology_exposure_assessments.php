<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('technology_exposure_assessments')) {
            return;
        }
        Schema::create('technology_exposure_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('risk_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('asset_id_snapshot')->constrained('assets')->restrictOnDelete();
            $table->foreignId('assessed_by')->constrained('users')->restrictOnDelete();
            $table->string('exposure_type');
            $table->string('title');
            $table->text('threat_scenario');
            $table->string('vulnerability_reference')->nullable();
            $table->text('vulnerability_description');
            $table->string('source_reference')->nullable();
            $table->unsignedTinyInteger('inherent_likelihood');
            $table->unsignedTinyInteger('inherent_impact');
            $table->unsignedTinyInteger('inherent_score');
            $table->unsignedTinyInteger('residual_likelihood');
            $table->unsignedTinyInteger('residual_impact');
            $table->unsignedTinyInteger('residual_score');
            $table->unsignedTinyInteger('appetite_threshold_snapshot');
            $table->string('state');
            $table->text('recommended_response');
            $table->date('review_due_at');
            $table->json('asset_snapshot');
            $table->json('governance_snapshot');
            $table->char('governance_fingerprint', 64);
            $table->timestamp('assessed_at');
            $table->timestamps();
            $table->unique(['risk_id', 'version']);
            $table->index(['risk_id', 'assessed_at']);
        });
    }

    public function down(): void
    {
        // Append-only technology exposure history is retained during routine rollback.
    }
};
