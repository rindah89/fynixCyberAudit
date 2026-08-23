<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_governance_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('appetite_threshold');
            $table->string('review_frequency');
            $table->text('strategic_objective')->nullable();
            $table->foreignId('business_service_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('context_notes')->nullable();
            $table->date('next_review_at');
            $table->timestamps();
        });

        Schema::create('risk_governance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_id')->constrained()->restrictOnDelete();
            $table->foreignId('risk_governance_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('decision');
            $table->text('summary');
            $table->string('evidence_reference')->nullable();
            $table->string('domain_snapshot');
            $table->unsignedTinyInteger('inherent_score_snapshot');
            $table->unsignedTinyInteger('residual_score_snapshot');
            $table->unsignedTinyInteger('appetite_threshold_snapshot');
            $table->json('asset_ids_snapshot');
            $table->json('implementation_ids_snapshot');
            $table->foreignId('business_service_id_snapshot')->nullable()->constrained('business_services')->restrictOnDelete();
            $table->json('governance_snapshot');
            $table->string('governance_fingerprint', 64);
            $table->date('next_review_at');
            $table->timestamp('reviewed_at');
            $table->timestamps();
        });

        Schema::create('risk_governance_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_id')->constrained()->restrictOnDelete();
            $table->foreignId('risk_governance_review_id')->unique()->constrained()->restrictOnDelete();
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
        Schema::dropIfExists('risk_governance_issues');
        Schema::dropIfExists('risk_governance_reviews');
        Schema::dropIfExists('risk_governance_profiles');
    }
};
