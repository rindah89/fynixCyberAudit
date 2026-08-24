<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('regulatory_sources')) {
            Schema::create('regulatory_sources', function (Blueprint $table): void {
                $table->id();
                $table->string('code')->unique();
                $table->string('title');
                $table->string('authority');
                $table->string('jurisdiction');
                $table->text('reference_url')->nullable();
                $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
                $table->string('status');
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('regulatory_requirements')) {
            Schema::create('regulatory_requirements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('regulatory_source_id')->constrained()->restrictOnDelete();
                $table->string('code');
                $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['regulatory_source_id', 'code']);
            });
        }

        if (! Schema::hasTable('regulatory_requirement_versions')) {
            Schema::create('regulatory_requirement_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('regulatory_requirement_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->string('change_type');
                $table->string('status');
                $table->string('title');
                $table->longText('requirement_text');
                $table->date('effective_at');
                $table->date('expires_at')->nullable();
                $table->json('policy_ids');
                $table->json('control_ids');
                $table->json('source_snapshot');
                $table->json('policy_snapshots');
                $table->json('control_snapshots');
                $table->char('content_fingerprint', 64);
                $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('published_at');
                $table->timestamps();
                $table->unique(['regulatory_requirement_id', 'version'], 'reg_requirement_version_unique');
            });
        }

        if (! Schema::hasTable('regulatory_change_assessments')) {
            Schema::create('regulatory_change_assessments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('regulatory_requirement_version_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('assessment_version');
                $table->string('applicability');
                $table->string('impact');
                $table->text('summary');
                $table->text('rationale');
                $table->foreignId('action_owner_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->date('action_due_at')->nullable();
                $table->json('requirement_snapshot');
                $table->json('policy_snapshots');
                $table->json('control_snapshots');
                $table->char('content_fingerprint', 64);
                $table->foreignId('assessed_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('assessed_at');
                $table->timestamps();
                $table->unique(['regulatory_requirement_version_id', 'assessment_version'], 'reg_change_assessment_version_unique');
                $table->index(['action_due_at', 'applicability']);
            });
        }
    }

    public function down(): void
    {
        // Regulatory source, requirement, version, and assessment history is retained during routine rollback.
    }
};
