<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executive_authority_bindings', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->primary();
            $table->uuid('suite_tenant_id')->unique();
            $table->uuid('customer_id')->unique();
            $table->string('authority', 32)->default('executive-hq');
            $table->unsignedBigInteger('version');
            $table->boolean('active')->default(true);
            $table->timestamp('verified_at');
            $table->timestamps();
        });
        Schema::create('executive_authority_binding_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->uuid('nonce')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('version');
            $table->string('event_digest', 64);
            $table->string('key_id', 64);
            $table->string('outcome', 24);
            $table->timestamp('received_at')->useCurrent();
            $table->unique(['company_id', 'version'], 'executive_binding_company_version_unique');
        });
        Schema::create('support_change_evidence_reviewers', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('company_id');
            $table->boolean('can_review')->default(false);
            $table->boolean('can_revoke')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->primary(['user_id', 'company_id']);
        });
        Schema::create('support_change_evidence_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->uuid('suite_tenant_id');
            $table->uuid('customer_id');
            $table->string('producer', 64);
            $table->uuid('request_id');
            $table->string('purpose', 16);
            $table->uuid('operation_id');
            $table->string('request_digest', 64);
            $table->json('request_json');
            $table->string('status', 16)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('receipt_json')->nullable();
            $table->string('receipt_digest', 64)->nullable();
            $table->string('signature', 128)->nullable();
            $table->string('key_id', 64)->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'producer', 'request_id'], 'support_evidence_request_unique');
            $table->unique(['company_id', 'operation_id'], 'support_evidence_operation_unique');
            $table->index(['status', 'expires_at']);
        });
        Schema::create('support_change_evidence_audit', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('acceptance_id')->nullable()->constrained('support_change_evidence_acceptances')->restrictOnDelete();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->string('reason_code', 64)->nullable();
            $table->string('details_digest', 64);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_change_evidence_audit');
        Schema::dropIfExists('support_change_evidence_acceptances');
        Schema::dropIfExists('support_change_evidence_reviewers');
        Schema::dropIfExists('executive_authority_binding_events');
        Schema::dropIfExists('executive_authority_bindings');
    }
};
