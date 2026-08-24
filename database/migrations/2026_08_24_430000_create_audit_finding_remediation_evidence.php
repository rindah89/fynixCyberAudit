<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('remediation_tasks', 'audit_finding_id')) {
            Schema::table('remediation_tasks', function (Blueprint $table): void {
                $table->foreignId('audit_finding_id')->nullable()->unique()->after('audit_item_id')->constrained('audit_findings')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('audit_finding_remediations')) {
            Schema::create('audit_finding_remediations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('audit_finding_id')->unique()->constrained('audit_findings')->restrictOnDelete();
                $table->foreignId('audit_management_response_id')->constrained('audit_management_responses')->restrictOnDelete();
                $table->foreignId('remediation_task_id')->unique()->constrained('remediation_tasks')->restrictOnDelete();
                $table->json('finding_snapshot');
                $table->json('response_snapshot');
                $table->json('task_snapshot');
                $table->foreignId('handed_off_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('handed_off_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('audit_finding_follow_ups')) {
            Schema::create('audit_finding_follow_ups', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('audit_finding_remediation_id')->constrained('audit_finding_remediations')->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->string('outcome');
                $table->text('summary');
                $table->string('evidence_reference', 2000)->nullable();
                $table->json('handoff_snapshot');
                $table->json('task_snapshot');
                $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('reviewed_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['audit_finding_remediation_id', 'version'], 'audit_finding_follow_up_version_unique');
            });
        }
    }

    public function down(): void
    {
        // Governed finding/remediation evidence is retained during routine rollback.
    }
};
