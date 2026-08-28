<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_statements', function (Blueprint $table) {
            $table->id();
            $table->uuid('statement_id')->unique();
            $table->uuid('delivery_id')->unique();
            $table->string('source', 32);
            $table->string('tenant_id', 128);
            $table->string('schema_version', 64);
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            $table->timestampTz('occurred_at');
            $table->char('payload_sha256', 64);
            $table->timestamps();
            $table->index(['source', 'tenant_id', 'occurred_at']);
        });

        Schema::create('governance_control_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governance_statement_id')->constrained()->cascadeOnDelete();
            $table->string('control_id', 16);
            $table->string('status', 32);
            $table->timestampTz('observed_at');
            $table->text('summary')->nullable();
            $table->json('evidence_refs');
            $table->json('metrics');
            $table->timestamps();
            $table->unique(['governance_statement_id', 'control_id'], 'governance_result_statement_control_unique');
            $table->index(['control_id', 'status']);
        });

        Schema::create('governance_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('tenant_id', 128);
            $table->string('control_id', 16);
            $table->string('status', 32)->default('open');
            $table->string('severity', 16);
            $table->text('reason');
            $table->text('resolution_notes')->nullable();
            $table->string('owner')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('first_detected_at');
            $table->timestampTz('last_detected_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('latest_control_result_id')->nullable()->constrained('governance_control_results')->nullOnDelete();
            $table->timestamps();
            $table->unique(['source', 'tenant_id', 'control_id'], 'governance_exception_source_control_unique');
            $table->index(['status', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_exceptions');
        Schema::dropIfExists('governance_control_results');
        Schema::dropIfExists('governance_statements');
    }
};
