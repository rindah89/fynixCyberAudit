<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_run_evidence', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 128);
            $table->string('source', 32);
            $table->uuid('source_run_ref');
            $table->char('schema_fingerprint', 64);
            $table->char('schedule_sha256', 64);
            $table->unsignedInteger('policy_count');
            $table->unsignedInteger('eligible_count');
            $table->unsignedInteger('disposed_count');
            $table->unsignedInteger('held_count');
            $table->unsignedInteger('preserved_policy_count');
            $table->unsignedInteger('pending_outbox_count');
            $table->string('outcome', 32);
            $table->timestamp('occurred_at');
            $table->string('evidence_ref', 2048);
            $table->char('evidence_sha256', 64);
            $table->string('review_status', 32)->default('pending_review');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->char('review_digest', 64)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'source', 'source_run_ref']);
            $table->index(['tenant_id', 'source', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_run_evidence');
    }
};
