<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_closeout_submissions')) {
            Schema::create('audit_closeout_submissions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('audit_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->string('opinion');
                $table->text('executive_summary');
                $table->text('scope_limitations')->nullable();
                $table->text('significant_matters');
                $table->text('recommendations_summary');
                $table->json('audit_snapshot');
                $table->json('engagement_baseline_snapshot')->nullable();
                $table->json('audit_item_snapshots');
                $table->json('data_request_snapshots');
                $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('submitted_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['audit_id', 'version']);
            });
        }

        if (! Schema::hasTable('audit_closeout_reviews')) {
            Schema::create('audit_closeout_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('audit_closeout_submission_id')->unique()->constrained()->restrictOnDelete();
                $table->string('decision');
                $table->text('review_summary');
                $table->json('report_snapshot');
                $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('reviewed_at');
                $table->string('report_disk')->nullable();
                $table->string('report_path', 1024)->nullable();
                $table->unsignedBigInteger('report_size')->nullable();
                $table->char('report_sha256', 64)->nullable();
                $table->char('fingerprint', 64);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Closeout and signed-report evidence survive routine rollback.
    }
};
