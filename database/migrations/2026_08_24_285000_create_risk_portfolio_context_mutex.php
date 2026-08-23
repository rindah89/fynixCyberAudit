<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('risk_portfolio_context_mutex')) {
            Schema::create('risk_portfolio_context_mutex', function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
            });
        }

        DB::table('risk_portfolio_context_mutex')->insertOrIgnore(['id' => 1]);
    }

    public function down(): void
    {
        // The singleton lock seam remains available during routine rollback.
    }
};
