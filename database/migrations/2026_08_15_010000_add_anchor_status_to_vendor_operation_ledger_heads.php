<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_operation_ledger_heads', function (Blueprint $table) {
            if (! Schema::hasColumn('vendor_operation_ledger_heads', 'last_anchor_at')) {
                $table->timestampTz('last_anchor_at')->nullable();
            }
            if (! Schema::hasColumn('vendor_operation_ledger_heads', 'last_anchor_key')) {
                $table->string('last_anchor_key', 1024)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendor_operation_ledger_heads', function (Blueprint $table) {
            $table->dropColumn(['last_anchor_at', 'last_anchor_key']);
        });
    }
};
