<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vendor_operation_ledger_heads')) {
            Schema::create('vendor_operation_ledger_heads', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->char('last_hash', 64);
                $table->unsignedBigInteger('event_count')->default(0);
                $table->string('integrity_status', 16)->default('ok');
                $table->timestampTz('integrity_checked_at')->nullable();
                $table->timestamps();
            });
        }
        if (! DB::table('vendor_operation_ledger_heads')->where('id', 1)->exists()) {
            DB::table('vendor_operation_ledger_heads')->insert([
                'id' => 1,
                'last_hash' => str_repeat('0', 64),
                'event_count' => 0,
                'integrity_status' => 'ok',
                'integrity_checked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('vendor_operation_events')) {
            Schema::create('vendor_operation_events', function (Blueprint $table) {
                $table->id();
                $table->uuid('request_id')->unique();
                $table->uuid('operation_id')->index();
                $table->uuid('delivery_id')->unique();
                $table->string('operator_subject', 190);
                $table->string('action', 120);
                $table->string('target', 190);
                $table->string('outcome', 32);
                $table->ipAddress('source_ip')->nullable();
                $table->string('itsm_record', 64)->nullable()->index();
                $table->char('before_sha256', 64)->nullable();
                $table->char('after_sha256', 64)->nullable();
                $table->json('metadata')->nullable();
                $table->timestampTz('occurred_at');
                $table->char('previous_hash', 64);
                $table->char('event_hash', 64)->unique();
                $table->timestamps();
                $table->index(['action', 'occurred_at']);
                $table->index(['target', 'occurred_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_operation_events');
        Schema::dropIfExists('vendor_operation_ledger_heads');
    }
};
