<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_case_closure_report_reviews')) {
            Schema::create('compliance_case_closure_report_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_closure_report_id')->constrained()->restrictOnDelete();
                $table->string('decision');
                $table->text('summary');
                $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
                $table->json('reviewer_snapshot');
                $table->longText('closure_report_snapshot');
                $table->timestamp('reviewed_at');
                $table->char('fingerprint', 64)->unique('cc_closure_review_fingerprint_unique');
                $table->timestamps();
                $table->unique('compliance_case_closure_report_id', 'cc_closure_report_review_unique');
            });
        }
    }

    public function down(): void
    {
        // Governed closure-report review evidence is retained during routine rollback.
    }
};
