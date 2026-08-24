<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('incident_timeline_entries')) {
            Schema::create('incident_timeline_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('incident_id')->constrained('incidents')->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->string('entry_type', 32);
                $table->string('visibility', 16);
                $table->timestamp('occurred_at');
                $table->text('summary');
                $table->text('details')->nullable();
                $table->boolean('pinned')->default(false);
                $table->json('incident_snapshot');
                $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('recorded_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['incident_id', 'version']);
            });
        }
    }

    public function down(): void
    {
        // Retain governed incident timeline evidence during routine rollback.
    }
};
