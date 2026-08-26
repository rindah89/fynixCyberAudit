<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_case_retention_classifications')) {
            Schema::create('compliance_case_retention_classifications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_id')->constrained('compliance_cases', indexName: 'cc_ret_case_fk')->restrictOnDelete();
                $table->unsignedTinyInteger('version');
                $table->string('policy_reference');
                $table->string('classification');
                $table->date('starts_on');
                $table->date('ends_on');
                $table->longText('rationale');
                $table->foreignId('classified_by')->constrained('users', indexName: 'cc_ret_actor_fk')->restrictOnDelete();
                $table->json('classifier_snapshot');
                $table->json('case_snapshot');
                $table->timestamp('classified_at');
                $table->char('fingerprint', 64)->unique('cc_ret_fingerprint_uq');
                $table->timestamps();
                $table->unique(['compliance_case_id', 'version'], 'cc_ret_case_version_uq');
            });
        }
        if (! Schema::hasTable('compliance_case_disposition_reviews')) {
            Schema::create('compliance_case_disposition_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_retention_classification_id')->unique('cc_disp_class_uq')
                    ->constrained('compliance_case_retention_classifications', indexName: 'cc_disp_class_fk')->restrictOnDelete();
                $table->string('decision', 20);
                $table->longText('summary');
                $table->foreignId('reviewed_by')->constrained('users', indexName: 'cc_disp_actor_fk')->restrictOnDelete();
                $table->json('reviewer_snapshot');
                $table->json('classification_snapshot');
                $table->timestamp('reviewed_at');
                $table->char('fingerprint', 64)->unique('cc_disp_fingerprint_uq');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Governed retention evidence is retained on routine rollback.
    }
};
