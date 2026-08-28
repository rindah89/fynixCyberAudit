<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suite_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 128);
            $table->string('source', 32);
            $table->uuid('source_event_ref');
            $table->uuid('subject_ref')->nullable();
            $table->string('action', 128);
            $table->string('outcome', 32);
            $table->uuid('correlation_ref')->nullable();
            $table->timestamp('occurred_at');
            $table->string('event_sha256', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['tenant_id', 'source', 'source_event_ref']);
            $table->index(['tenant_id', 'source', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_audit_events');
    }
};
