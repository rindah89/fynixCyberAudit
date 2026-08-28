<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privacy_requests', function (Blueprint $table): void {
            $table->uuid('source_request_ref')->nullable()->after('source');
            $table->unique(['tenant_id', 'source', 'source_request_ref'], 'privacy_requests_source_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('privacy_requests', function (Blueprint $table): void {
            $table->dropUnique('privacy_requests_source_ref_unique');
            $table->dropColumn('source_request_ref');
        });
    }
};
