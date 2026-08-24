<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('policy_revisions')) {
            Schema::create('policy_revisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('policy_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->string('status');
                $table->text('change_summary');
                $table->date('proposed_effective_date');
                $table->json('policy_snapshot');
                $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('submitted_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['policy_id', 'version']);
            });
        }
        if (! Schema::hasTable('policy_revision_reviews')) {
            Schema::create('policy_revision_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('policy_revision_id')->unique()->constrained()->restrictOnDelete();
                $table->string('decision');
                $table->text('review_summary');
                $table->json('revision_snapshot');
                $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('reviewed_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Governed policy publication history is retained during routine rollback.
    }
};
