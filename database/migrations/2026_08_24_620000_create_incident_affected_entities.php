<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('incident_affected_entities')) {
            Schema::create('incident_affected_entities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('incident_id')->constrained('incidents')->restrictOnDelete();
                $table->string('entity_type', 32);
                $table->unsignedBigInteger('entity_id_snapshot');
                $table->json('entity_snapshot');
                $table->text('impact_summary');
                $table->text('control_failure_note')->nullable();
                $table->foreignId('linked_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('linked_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['incident_id', 'entity_type', 'entity_id_snapshot'], 'incident_affected_entity_unique');
            });
        }
    }

    public function down(): void
    {
        // Retain governed incident scope evidence during routine rollback.
    }
};
