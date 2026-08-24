<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('incident_final_reports')) {
            Schema::create('incident_final_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('incident_id')->constrained('incidents')->restrictOnDelete();
                $table->unsignedTinyInteger('version');
                $table->json('report_snapshot');
                $table->json('evidence_attachment_ids');
                $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('generated_at');
                $table->string('report_disk');
                $table->string('report_path');
                $table->unsignedBigInteger('report_size');
                $table->char('report_sha256', 64);
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['incident_id', 'version']);
            });
        }
    }

    public function down(): void
    {
        // Retain governed incident report evidence during routine rollback.
    }
};
