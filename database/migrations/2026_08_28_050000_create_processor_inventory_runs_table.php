<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processor_inventory_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 16);
            $table->unsignedInteger('source_count')->default(0);
            $table->unsignedInteger('active_count')->default(0);
            $table->unsignedInteger('retired_count')->default(0);
            $table->string('inventory_digest', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('completed_at');
            $table->timestamps();
            $table->index(['status', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processor_inventory_runs');
    }
};
