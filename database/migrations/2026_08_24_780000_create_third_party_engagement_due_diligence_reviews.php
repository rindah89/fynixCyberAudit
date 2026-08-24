<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('third_party_engagements', 'due_diligence_review_snapshot')) {
            Schema::table('third_party_engagements', fn (Blueprint $table) => $table->json('due_diligence_review_snapshot')->nullable());
        }
        if (! Schema::hasTable('third_party_engagement_due_diligence_reviews')) {
            Schema::create('third_party_engagement_due_diligence_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('third_party_engagement_id')->constrained(indexName: 'tp_eng_dd_review_engagement_fk')->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->foreignId('survey_id')->constrained(indexName: 'tp_eng_dd_review_survey_fk')->restrictOnDelete();
                $table->unsignedTinyInteger('cybersecurity_rating');
                $table->unsignedTinyInteger('privacy_rating');
                $table->unsignedTinyInteger('resilience_rating');
                $table->unsignedTinyInteger('compliance_rating');
                $table->unsignedTinyInteger('financial_rating');
                $table->text('findings_summary');
                $table->string('decision', 30);
                $table->text('conditions')->nullable();
                $table->text('rationale');
                $table->date('next_review_at');
                $table->json('engagement_snapshot');
                $table->json('survey_snapshot');
                $table->json('document_snapshots');
                $table->json('risk_approval_snapshot');
                $table->char('engagement_event_fingerprint', 64);
                $table->foreignId('reviewed_by')->constrained('users', indexName: 'tp_eng_dd_review_actor_fk')->restrictOnDelete();
                $table->timestamp('reviewed_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['third_party_engagement_id', 'version'], 'tp_eng_dd_review_version_unique');
            });
        }
    }

    public function down(): void
    {
        // Governed due-diligence and approval evidence is retained during routine rollback.
    }
};
