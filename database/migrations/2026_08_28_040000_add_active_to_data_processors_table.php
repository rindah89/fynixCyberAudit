<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_processors', function (Blueprint $table): void {
            $table->boolean('active')->default(true)->after('review_due_at');
            $table->index(['tenant_id', 'source', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('data_processors', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'source', 'active']);
            $table->dropColumn('active');
        });
    }
};
