<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_sso')->default(false)->after('password_reset_required');
            $table->boolean('is_break_glass')->default(false)->after('is_sso');
            $table->string('sso_subject')->nullable()->after('is_break_glass');
            $table->string('sso_issuer', 500)->nullable()->after('sso_subject');
            $table->unique('sso_subject');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['sso_subject']);
            $table->dropColumn(['is_sso', 'is_break_glass', 'sso_subject', 'sso_issuer']);
        });
    }
};
