<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained()->restrictOnDelete();
            $table->foreignId('control_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('code');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('frequency')->default('annual');
            $table->timestamp('next_due_at')->nullable();
            $table->timestamp('last_attested_at')->nullable();
            $table->string('last_outcome')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['policy_id', 'code']);
            $table->index(['is_active', 'next_due_at']);
        });

        Schema::create('policy_attestations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_obligation_id')->constrained()->restrictOnDelete();
            $table->foreignId('attested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('policy_exception_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('outcome');
            $table->text('statement');
            $table->string('evidence_reference')->nullable();
            $table->timestamp('attested_at');
            $table->timestamps();

            $table->index(['policy_obligation_id', 'attested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_attestations');
        Schema::dropIfExists('policy_obligations');
    }
};
