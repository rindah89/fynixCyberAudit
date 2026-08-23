<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('criticality')->default('medium');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('business_impact_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_service_id')->constrained()->restrictOnDelete();
            $table->foreignId('analyst_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('maximum_tolerable_downtime_minutes');
            $table->unsignedInteger('recovery_time_objective_minutes');
            $table->unsignedInteger('recovery_point_objective_minutes');
            $table->string('operational_impact');
            $table->string('regulatory_impact')->nullable();
            $table->string('reputational_impact')->nullable();
            $table->decimal('financial_impact_per_hour', 15, 2)->nullable();
            $table->text('rationale');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['business_service_id', 'version']);
        });

        Schema::create('business_service_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dependent_service_id')->nullable()->constrained('business_services')->restrictOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('control_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('dependency_type');
            $table->string('criticality')->default('medium');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('recovery_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_service_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('title');
            $table->text('strategy');
            $table->text('activation_criteria');
            $table->text('recovery_procedure');
            $table->text('communication_plan');
            $table->string('status')->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->date('review_due_at');
            $table->timestamps();

            $table->unique(['business_service_id', 'version']);
        });

        Schema::create('recovery_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recovery_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('facilitator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            $table->text('scenario');
            $table->timestamp('scheduled_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('actual_recovery_time_minutes')->nullable();
            $table->unsignedInteger('actual_recovery_point_minutes')->nullable();
            $table->unsignedInteger('rto_objective_minutes')->nullable();
            $table->unsignedInteger('rpo_objective_minutes')->nullable();
            $table->string('outcome')->nullable();
            $table->text('observations')->nullable();
            $table->string('evidence_reference')->nullable();
            $table->timestamps();
        });

        Schema::create('resilience_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recovery_exercise_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('business_service_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('severity')->default('high');
            $table->string('status')->default('open');
            $table->date('due_at')->nullable();
            $table->foreignId('remediation_task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resilience_issues');
        Schema::dropIfExists('recovery_exercises');
        Schema::dropIfExists('recovery_plans');
        Schema::dropIfExists('business_service_dependencies');
        Schema::dropIfExists('business_impact_analyses');
        Schema::dropIfExists('business_services');
    }
};
