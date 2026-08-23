<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_test_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_id')->constrained()->restrictOnDelete();
            $table->foreignId('implementation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->string('metric_type');
            $table->string('operator');
            $table->string('expected_value');
            $table->string('frequency')->default('monthly');
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_executed_at')->nullable();
            $table->string('last_outcome')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'next_run_at']);
        });

        Schema::create('control_test_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_test_definition_id')->constrained()->restrictOnDelete();
            $table->foreignId('executed_by')->constrained('users')->restrictOnDelete();
            $table->string('observed_value');
            $table->string('metric_type');
            $table->string('operator');
            $table->string('expected_value');
            $table->string('outcome');
            $table->text('result_reason');
            $table->text('notes')->nullable();
            $table->string('evidence_reference')->nullable();
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->index(['control_test_definition_id', 'executed_at']);
        });

        Schema::create('control_test_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_test_execution_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('control_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('status')->default('open');
            $table->timestamp('detected_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_test_findings');
        Schema::dropIfExists('control_test_executions');
        Schema::dropIfExists('control_test_definitions');
    }
};
