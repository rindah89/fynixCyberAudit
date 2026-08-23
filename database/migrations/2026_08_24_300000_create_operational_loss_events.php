<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operational_loss_events')) {
            return;
        }

        Schema::create('operational_loss_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('risk_id')->constrained()->restrictOnDelete();
            $table->foreignId('business_service_id_snapshot')->constrained('business_services')->restrictOnDelete();
            $table->json('business_service_snapshot');
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->string('category');
            $table->date('occurred_at');
            $table->date('detected_at');
            $table->text('summary');
            $table->decimal('gross_loss', 16, 2);
            $table->decimal('recoveries', 16, 2)->default(0);
            $table->decimal('net_loss', 16, 2);
            $table->char('currency', 3);
            $table->string('source_reference')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['risk_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        // Operational loss-event history is retained during routine rollback.
    }
};
