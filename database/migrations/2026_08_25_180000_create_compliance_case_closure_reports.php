<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_case_closure_reports')) {
            Schema::create('compliance_case_closure_reports', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_id')->constrained()->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->longText('report_snapshot');
                $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
                $table->json('generator_snapshot');
                $table->timestamp('generated_at');
                $table->string('report_disk');
                $table->string('report_path', 1000);
                $table->unsignedBigInteger('report_size');
                $table->char('report_sha256', 64);
                $table->char('fingerprint', 64)->unique('cc_closure_report_fingerprint_unique');
                $table->timestamps();
                $table->unique(['compliance_case_id', 'version'], 'cc_closure_report_version_unique');
            });
        }
    }

    public function down(): void
    {
        // Governed closure-report evidence is retained during routine rollback.
    }
};
