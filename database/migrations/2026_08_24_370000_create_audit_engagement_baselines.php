<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_engagement_baselines')) {
            Schema::create('audit_engagement_baselines', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('audit_id')->unique()->constrained()->restrictOnDelete();
                $table->foreignId('audit_plan_item_id')->unique()->constrained()->restrictOnDelete();
                $table->text('objective');
                $table->text('scope');
                $table->text('exclusions')->nullable();
                $table->json('team_user_ids');
                $table->json('audit_snapshot');
                $table->json('plan_snapshot');
                $table->json('entity_assessment_snapshot');
                $table->foreignId('launched_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('launched_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Engagement baselines link approved planning and audit evidence and survive routine rollback.
    }
};
