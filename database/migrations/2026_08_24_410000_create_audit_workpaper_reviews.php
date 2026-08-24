<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_workpaper_reviews')) {
            Schema::create('audit_workpaper_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('audit_procedure_execution_id')->unique()->constrained()->restrictOnDelete();
                $table->string('decision', 30);
                $table->text('review_summary');
                $table->json('execution_snapshot');
                $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('reviewed_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Retain governed supervisory-review evidence during routine rollback.
    }
};
