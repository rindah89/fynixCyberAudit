<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('mode')->default('manual');
            $table->string('status')->default('draft');
            $table->string('recurrence')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('risk_assessment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->nullable()->constrained('risks')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('inherent_likelihood')->nullable();
            $table->unsignedTinyInteger('inherent_impact')->nullable();
            $table->unsignedSmallInteger('inherent_risk')->nullable();
            $table->unsignedTinyInteger('residual_likelihood')->nullable();
            $table->unsignedTinyInteger('residual_impact')->nullable();
            $table->unsignedSmallInteger('residual_risk')->nullable();
            $table->string('treatment')->nullable();
            $table->text('justification')->nullable();
            $table->json('ai_meta')->nullable();
            $table->timestamps();
        });

        Schema::create('risk_assessment_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('contributor');
            $table->timestamps();
            $table->unique(['risk_assessment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessment_collaborators');
        Schema::dropIfExists('risk_assessment_items');
        Schema::dropIfExists('risk_assessments');
    }
};
