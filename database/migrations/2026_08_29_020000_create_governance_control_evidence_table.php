<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_control_evidence', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 128);
            $table->string('source', 32);
            $table->string('control_id', 8);
            $table->uuid('source_evidence_ref');
            $table->timestamp('observed_at');
            $table->string('evidence_ref', 2048);
            $table->string('evidence_sha256', 64);
            $table->string('review_status', 32)->default('pending_review');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_digest', 64)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'source', 'source_evidence_ref'], 'control_evidence_source_ref_unique');
            $table->index(['tenant_id', 'source', 'control_id', 'observed_at'], 'control_evidence_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_control_evidence');
    }
};
