<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('auditable_entities')) {
            Schema::create('auditable_entities', function (Blueprint $table): void {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('entity_type');
                $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
                $table->string('criticality');
                $table->string('status');
                $table->string('assessment_frequency');
                $table->date('next_assessment_at');
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }
        if (! Schema::hasTable('auditable_entity_risk')) {
            Schema::create('auditable_entity_risk', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('auditable_entity_id')->constrained()->restrictOnDelete();
                $table->foreignId('risk_id')->constrained()->restrictOnDelete();
                $table->timestamps();
                $table->unique(['auditable_entity_id', 'risk_id']);
            });
        }
        if (! Schema::hasTable('auditable_entity_control')) {
            Schema::create('auditable_entity_control', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('auditable_entity_id')->constrained()->restrictOnDelete();
                $table->foreignId('control_id')->constrained()->restrictOnDelete();
                $table->timestamps();
                $table->unique(['auditable_entity_id', 'control_id']);
            });
        }
        if (! Schema::hasTable('auditable_entity_assessments')) {
            Schema::create('auditable_entity_assessments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('auditable_entity_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->unsignedTinyInteger('inherent_likelihood');
                $table->unsignedTinyInteger('inherent_impact');
                $table->unsignedTinyInteger('inherent_score');
                $table->unsignedTinyInteger('residual_likelihood');
                $table->unsignedTinyInteger('residual_impact');
                $table->unsignedTinyInteger('residual_score');
                $table->string('priority_band');
                $table->text('rationale');
                $table->json('entity_snapshot');
                $table->json('risk_snapshots');
                $table->json('control_snapshots');
                $table->char('governance_fingerprint', 64);
                $table->date('next_assessment_at');
                $table->foreignId('assessed_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('assessed_at');
                $table->timestamps();
                $table->unique(['auditable_entity_id', 'version']);
            });
        }
        if (! Schema::hasTable('audit_plans')) {
            Schema::create('audit_plans', function (Blueprint $table): void {
                $table->id();
                $table->unsignedSmallInteger('plan_year');
                $table->string('name');
                $table->text('objective');
                $table->foreignId('manager_id')->constrained('users')->restrictOnDelete();
                $table->string('status');
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->json('approval_snapshot')->nullable();
                $table->char('approval_fingerprint', 64)->nullable();
                $table->timestamps();
                $table->unique(['plan_year', 'name']);
            });
        }
        if (! Schema::hasTable('audit_plan_items')) {
            Schema::create('audit_plan_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('audit_plan_id')->constrained()->restrictOnDelete();
                $table->foreignId('auditable_entity_id')->constrained()->restrictOnDelete();
                $table->foreignId('auditable_entity_assessment_id')->constrained()->restrictOnDelete();
                $table->foreignId('audit_id')->nullable()->constrained()->restrictOnDelete();
                $table->string('status');
                $table->date('planned_start_at');
                $table->date('planned_end_at');
                $table->text('rationale');
                $table->json('entity_assessment_snapshot');
                $table->unsignedInteger('priority_rank');
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->unique(['audit_plan_id', 'auditable_entity_id']);
            });
        }
    }

    public function down(): void
    {
        // Audit-universe assessments and approved planning evidence are retained during routine rollback.
    }
};
