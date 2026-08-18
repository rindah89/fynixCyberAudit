<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('suite_entity_links', 'work_kind')) {
            Schema::table('suite_entity_links', function (Blueprint $table) {
                $table->string('work_kind')->nullable()->after('relation');
            });
        }
        if (! Schema::hasColumn('suite_entity_links', 'remote_closed_at')) {
            Schema::table('suite_entity_links', function (Blueprint $table) {
                $table->timestampTz('remote_closed_at')->nullable()->after('remote_status');
            });
        }

        if (! Schema::hasTable('suite_inbound_high_water')) {
            Schema::create('suite_inbound_high_water', function (Blueprint $table) {
                $table->id();
                $table->uuid('local_tenant_id');
                $table->string('source', 64);
                $table->string('entity_type', 64);
                $table->string('entity_id', 255);
                $table->timestampTz('occurred_at');
                $table->timestamps();
                $table->unique(['local_tenant_id', 'source', 'entity_type', 'entity_id'], 'suite_high_water_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_inbound_high_water');
        Schema::table('suite_entity_links', function (Blueprint $table) {
            $table->dropColumn(['work_kind', 'remote_closed_at']);
        });
    }
};
