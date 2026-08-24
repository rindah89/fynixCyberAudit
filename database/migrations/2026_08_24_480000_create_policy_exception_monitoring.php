<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('policy_exceptions', 'review_frequency_days')) {
            Schema::table('policy_exceptions', function (Blueprint $table): void {
                $table->unsignedSmallInteger('review_frequency_days')->nullable()->after('expiration_date');
            });
        }
        if (! Schema::hasColumn('policy_exceptions', 'next_review_at')) {
            Schema::table('policy_exceptions', function (Blueprint $table): void {
                $table->dateTime('next_review_at')->nullable()->after('review_frequency_days');
            });
        }
        if (! Schema::hasColumn('policy_exceptions', 'latest_monitoring_outcome')) {
            Schema::table('policy_exceptions', function (Blueprint $table): void {
                $table->string('latest_monitoring_outcome')->nullable()->after('next_review_at');
            });
        }

        if (! Schema::hasTable('policy_exception_monitoring_reviews')) {
            Schema::create('policy_exception_monitoring_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('policy_exception_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->string('outcome');
                $table->text('review_summary');
                $table->text('control_effectiveness');
                $table->string('evidence_reference')->nullable();
                $table->json('exception_snapshot');
                $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
                $table->dateTime('reviewed_at');
                $table->dateTime('next_review_at');
                $table->string('fingerprint', 64);
                $table->timestamps();
                $table->unique(['policy_exception_id', 'version'], 'policy_exception_monitoring_version_unique');
                $table->index(['policy_exception_id', 'reviewed_at'], 'policy_exception_monitoring_history_index');
            });
        }
    }

    public function down(): void
    {
        // Governed monitoring history is intentionally retained during routine rollback.
    }
};
