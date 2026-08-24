<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('third_party_contract_risk_reviews')) {
            Schema::create('third_party_contract_risk_reviews', function (Blueprint $table) {
                $table->id();
                $table->foreignId('third_party_engagement_id');
                $table->unsignedSmallInteger('version');
                $table->string('contract_reference');
                $table->string('agreement_type');
                $table->date('effective_at');
                $table->date('expires_at');
                $table->date('proposed_term_end_at')->nullable();
                $table->date('proposed_next_review_at')->nullable();
                $table->boolean('confidentiality_terms');
                $table->boolean('data_protection_terms');
                $table->boolean('incident_notification_terms');
                $table->boolean('audit_rights');
                $table->boolean('subcontractor_controls');
                $table->boolean('business_continuity_terms');
                $table->boolean('termination_assistance');
                $table->text('service_level_summary');
                $table->text('liability_summary');
                $table->text('exit_terms_summary');
                $table->text('exceptions_summary')->nullable();
                $table->string('decision');
                $table->text('conditions')->nullable();
                $table->text('rationale');
                $table->json('engagement_snapshot');
                $table->json('risk_approval_snapshot');
                $table->char('engagement_event_fingerprint', 64);
                $table->foreignId('reviewed_by');
                $table->timestamp('reviewed_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['third_party_engagement_id', 'version'], 'third_party_contract_review_version_uq');
                $table->foreign('third_party_engagement_id', 'tp_contract_review_engagement_fk')->references('id')->on('third_party_engagements')->restrictOnDelete();
                $table->foreign('reviewed_by', 'tp_contract_review_reviewer_fk')->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Retain contract-risk evidence during routine rollback.
    }
};
