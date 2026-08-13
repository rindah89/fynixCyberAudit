<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suite_entity_links', function (Blueprint $table) {
            $table->string('work_kind')->nullable()->after('relation');
            $table->timestampTz('remote_closed_at')->nullable()->after('remote_status');
        });

        Schema::create('suite_inbound_high_water', function (Blueprint $table) {
            $table->id();
            $table->uuid('local_tenant_id');
            $table->string('source');
            $table->string('entity_type');
            $table->string('entity_id');
            $table->timestampTz('occurred_at');
            $table->timestamps();
            $table->unique(['local_tenant_id', 'source', 'entity_type', 'entity_id'], 'suite_high_water_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_inbound_high_water');
        Schema::table('suite_entity_links', function (Blueprint $table) {
            $table->dropColumn(['work_kind', 'remote_closed_at']);
        });
    }
};
