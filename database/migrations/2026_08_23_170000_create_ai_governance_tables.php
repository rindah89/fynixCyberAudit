<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_systems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('provider_name');
            $table->string('model_name');
            $table->string('deployment_type');
            $table->string('lifecycle_status')->default('proposed');
            $table->string('criticality')->default('medium');
            $table->text('intended_purpose');
            $table->text('prohibited_uses')->nullable();
            $table->text('human_oversight');
            $table->json('data_categories')->nullable();
            $table->date('next_review_at');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ai_use_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_system_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->text('purpose');
            $table->string('decision_impact');
            $table->string('affected_population');
            $table->boolean('uses_personal_data')->default(false);
            $table->boolean('uses_sensitive_data')->default(false);
            $table->boolean('automated_decision')->default(false);
            $table->date('next_monitoring_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ai_risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_use_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedTinyInteger('likelihood');
            $table->unsignedTinyInteger('impact');
            $table->unsignedTinyInteger('inherent_score');
            $table->unsignedTinyInteger('residual_likelihood');
            $table->unsignedTinyInteger('residual_impact');
            $table->unsignedTinyInteger('residual_score');
            $table->json('risk_categories');
            $table->text('assessment_summary');
            $table->text('mitigation_summary');
            $table->timestamp('assessed_at');
            $table->timestamps();
            $table->unique(['ai_use_case_id', 'version']);
        });

        Schema::create('ai_use_case_control', function (Blueprint $table) {
            $table->foreignId('ai_use_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('control_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->primary(['ai_use_case_id', 'control_id']);
        });
        Schema::create('ai_use_case_risk', function (Blueprint $table) {
            $table->foreignId('ai_use_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->primary(['ai_use_case_id', 'risk_id']);
        });

        Schema::create('ai_governance_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_use_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('ai_risk_assessment_id')->constrained()->restrictOnDelete();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->string('decision');
            $table->text('rationale');
            $table->text('conditions')->nullable();
            $table->unsignedInteger('assessment_version');
            $table->unsignedTinyInteger('residual_score');
            $table->unsignedInteger('controls_count');
            $table->unsignedInteger('risks_count');
            $table->json('control_ids');
            $table->json('risk_ids');
            $table->json('system_snapshot');
            $table->json('use_case_snapshot');
            $table->string('governance_fingerprint', 64);
            $table->date('expires_at')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
        });

        Schema::create('ai_monitoring_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_use_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('ai_governance_decision_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('assessment_version');
            $table->string('governance_fingerprint', 64);
            $table->string('outcome');
            $table->text('performance_summary');
            $table->unsignedInteger('incidents_count')->default(0);
            $table->unsignedInteger('complaints_count')->default(0);
            $table->string('evidence_reference')->nullable();
            $table->date('next_review_at');
            $table->timestamp('reviewed_at');
            $table->timestamps();
        });

        Schema::create('ai_governance_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_use_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('ai_monitoring_review_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('severity')->default('high');
            $table->string('status')->default('open');
            $table->foreignId('remediation_task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_governance_issues');
        Schema::dropIfExists('ai_monitoring_reviews');
        Schema::dropIfExists('ai_governance_decisions');
        Schema::dropIfExists('ai_use_case_risk');
        Schema::dropIfExists('ai_use_case_control');
        Schema::dropIfExists('ai_risk_assessments');
        Schema::dropIfExists('ai_use_cases');
        Schema::dropIfExists('ai_systems');
    }
};
