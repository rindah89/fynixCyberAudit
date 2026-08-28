<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 128);
            $table->string('source', 32);
            $table->string('subject_ref', 255);
            $table->string('right', 32);
            $table->string('lawful_basis', 64);
            $table->string('status', 32)->default('open');
            $table->timestamp('requested_at');
            $table->timestamp('due_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('evidence_ref', 2048)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'source', 'status']);
        });

        Schema::create('retention_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 128);
            $table->string('source', 32);
            $table->string('record_class', 128);
            $table->unsignedInteger('retention_days');
            $table->string('disposition_action', 32);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'source', 'record_class']);
        });

        Schema::create('legal_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('retention_policy_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 1000);
            $table->timestamp('placed_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('disposition_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('retention_policy_id')->constrained()->restrictOnDelete();
            $table->string('record_ref', 255);
            $table->string('action', 32);
            $table->timestamp('eligible_at');
            $table->timestamp('disposed_at');
            $table->string('evidence_ref', 2048);
            $table->timestamps();
            $table->unique(['retention_policy_id', 'record_ref']);
        });

        Schema::create('data_processors', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 128);
            $table->string('source', 32);
            $table->string('name');
            $table->text('purpose');
            $table->json('data_categories');
            $table->json('processing_countries');
            $table->string('transfer_mechanism')->nullable();
            $table->string('agreement_owner');
            $table->date('review_due_at');
            $table->string('status', 32)->default('pending_review');
            $table->timestamps();
            $table->unique(['tenant_id', 'source', 'name']);
        });

        Schema::create('recovery_evidence', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 128);
            $table->string('source', 32);
            $table->string('kind', 32);
            $table->timestamp('occurred_at');
            $table->string('outcome', 32);
            $table->string('evidence_ref', 2048);
            $table->timestamps();
            $table->index(['tenant_id', 'source', 'kind', 'occurred_at']);
        });

        Schema::create('governance_control_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('delivery_id')->unique();
            $table->string('tenant_id', 128);
            $table->string('source', 32);
            $table->string('command', 64);
            $table->string('resource_type', 64);
            $table->unsignedBigInteger('resource_id');
            $table->string('payload_sha256', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_control_deliveries');
        Schema::dropIfExists('recovery_evidence');
        Schema::dropIfExists('data_processors');
        Schema::dropIfExists('disposition_receipts');
        Schema::dropIfExists('legal_holds');
        Schema::dropIfExists('retention_policies');
        Schema::dropIfExists('privacy_requests');
    }
};
