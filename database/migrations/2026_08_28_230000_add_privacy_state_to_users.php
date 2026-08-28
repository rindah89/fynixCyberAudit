<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('privacy_restricted_at')->nullable()->index();
            $table->timestamp('processing_objection_at')->nullable();
            $table->timestamp('privacy_erased_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['privacy_restricted_at', 'processing_objection_at', 'privacy_erased_at']);
        });
    }
};
