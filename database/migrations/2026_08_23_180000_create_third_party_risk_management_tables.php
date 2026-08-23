<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('survey_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedTinyInteger('likelihood');
            $table->unsignedTinyInteger('impact');
            $table->unsignedTinyInteger('inherent_score');
            $table->unsignedTinyInteger('residual_likelihood');
            $table->unsignedTinyInteger('residual_impact');
            $table->unsignedTinyInteger('residual_score');
            $table->unsignedTinyInteger('survey_score_snapshot')->nullable();
            $table->json('risk_categories');
            $table->text('assessment_summary');
            $table->text('treatment_summary');
            $table->timestamp('assessed_at');
            $table->timestamps();
            $table->unique(['vendor_id', 'version']);
        });

        Schema::create('vendor_risk', function (Blueprint $table) {
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->primary(['vendor_id', 'risk_id']);
        });

        Schema::create('vendor_risk_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_risk_assessment_id')->constrained()->restrictOnDelete();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->string('decision');
            $table->text('rationale');
            $table->text('conditions')->nullable();
            $table->unsignedInteger('assessment_version');
            $table->unsignedTinyInteger('residual_score');
            $table->json('risk_ids');
            $table->string('governance_fingerprint', 64);
            $table->date('expires_at')->nullable();
            $table->date('next_review_at')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
        });

        Schema::create('vendor_risk_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_risk_decision_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('outcome');
            $table->text('summary');
            $table->string('evidence_reference')->nullable();
            $table->unsignedInteger('assessment_version');
            $table->string('governance_fingerprint', 64);
            $table->date('next_review_at');
            $table->timestamp('reviewed_at');
            $table->timestamps();
        });

        Schema::create('vendor_risk_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_risk_review_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('severity');
            $table->string('status')->default('open');
            $table->foreignId('remediation_task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_risk_issues');
        Schema::dropIfExists('vendor_risk_reviews');
        Schema::dropIfExists('vendor_risk_decisions');
        Schema::dropIfExists('vendor_risk');
        Schema::dropIfExists('vendor_risk_assessments');
    }
};
