<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_requester_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('key_id', 64);
            $table->string('token_digest', 64);
            $table->unsignedBigInteger('company_id');
            $table->string('profile', 96);
            $table->boolean('active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['key_id', 'company_id', 'profile'], 'evidence_key_profile_unique');
            $table->unique(['token_digest', 'company_id', 'profile'], 'evidence_token_profile_unique');
        });
        Schema::create('evidence_profile_reviewers', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('company_id');
            $table->string('profile', 96);
            $table->boolean('can_review')->default(false);
            $table->boolean('can_revoke')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->primary(['user_id', 'company_id', 'profile']);
        });
        Schema::create('evidence_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->string('profile', 96);
            $table->unsignedBigInteger('company_id');
            $table->uuid('suite_tenant_id');
            $table->uuid('customer_id');
            $table->string('requester_key_id', 64);
            $table->uuid('request_id');
            $table->uuid('operation_id');
            $table->string('request_digest', 64);
            $table->json('request_json');
            $table->string('status', 16)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->json('receipt_json')->nullable();
            $table->string('receipt_digest', 64)->nullable();
            $table->string('signature', 128)->nullable();
            $table->string('key_id', 64)->nullable();
            $table->timestamp('retention_until');
            $table->timestamps();
            $table->unique(['company_id', 'profile', 'request_id'], 'evidence_profile_request_unique');
            $table->unique(['company_id', 'profile', 'operation_id'], 'evidence_profile_operation_unique');
            $table->index(['status', 'expires_at']);
        });
        Schema::create('evidence_authorization_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('authorization_id')->constrained('evidence_authorizations')->restrictOnDelete();
            $table->uuid('nonce')->unique();
            $table->string('token_digest', 64)->unique();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->unique('authorization_id');
        });
        Schema::create('evidence_authorization_audit', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('authorization_id')->nullable()->constrained('evidence_authorizations')->restrictOnDelete();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('profile', 96)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->string('reason_code', 64)->nullable();
            $table->string('previous_digest', 64)->nullable();
            $table->string('event_digest', 64)->unique();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_authorization_audit');
        Schema::dropIfExists('evidence_authorization_claims');
        Schema::dropIfExists('evidence_authorizations');
        Schema::dropIfExists('evidence_profile_reviewers');
        Schema::dropIfExists('evidence_requester_keys');
    }
};
