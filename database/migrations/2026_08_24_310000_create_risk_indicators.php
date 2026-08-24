<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('risk_indicators')) {
            Schema::create('risk_indicators', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('risk_id')->constrained()->restrictOnDelete();
                $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
                $table->string('code');
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('unit', 50);
                $table->string('direction');
                $table->decimal('warning_threshold', 21, 6);
                $table->decimal('critical_threshold', 21, 6);
                $table->string('frequency');
                $table->timestamp('next_due_at');
                $table->timestamp('last_observed_at')->nullable();
                $table->string('last_status')->nullable();
                $table->boolean('is_active')->default(true);
                $table->softDeletes();
                $table->timestamps();
                $table->unique(['risk_id', 'code']);
            });
        }
        if (! Schema::hasTable('risk_indicator_observations')) {
            Schema::create('risk_indicator_observations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('risk_indicator_id')->constrained()->restrictOnDelete();
                $table->foreignId('observed_by')->constrained('users')->restrictOnDelete();
                $table->decimal('observed_value', 21, 6);
                $table->string('unit_snapshot', 50);
                $table->string('direction_snapshot');
                $table->decimal('warning_threshold_snapshot', 21, 6);
                $table->decimal('critical_threshold_snapshot', 21, 6);
                $table->string('status');
                $table->string('reason');
                $table->text('notes')->nullable();
                $table->string('source_reference')->nullable();
                $table->timestamp('observed_at');
                $table->timestamps();
                $table->index(['risk_indicator_id', 'observed_at']);
            });
        }
    }

    public function down(): void
    {
        // Indicator definitions and append-only observations are retained during routine rollback.
    }
};
