<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suite_entity_links')) {
            Schema::create('suite_entity_links', function (Blueprint $table) {
            $table->id();
            $table->string('local_type', 64);
            $table->unsignedBigInteger('local_id');
            $table->string('system', 32);
            $table->string('entity_type', 64);
            $table->string('entity_id', 255);
            $table->string('relation', 64)->default('derived_from');
            $table->string('remote_status')->nullable();
            $table->string('remote_url')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['local_type', 'local_id', 'system', 'entity_type', 'entity_id', 'relation'], 'suite_links_unique');
            $table->index(['system', 'entity_type', 'entity_id']);
            });
        }

        if (! Schema::hasTable('suite_inbound_deliveries')) {
            Schema::create('suite_inbound_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_id')->unique();
            $table->string('event_type');
            $table->string('source');
            $table->string('outcome');
            $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('suite_inbound_deliveries');
        Schema::dropIfExists('suite_entity_links');
    }
};
