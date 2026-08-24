<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_findings')) {
            Schema::create('audit_findings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('audit_id')->constrained()->restrictOnDelete();
                $table->foreignId('audit_item_id')->constrained()->restrictOnDelete();
                $table->string('code', 30)->unique();
                $table->string('title');
                $table->string('severity', 20);
                $table->text('condition');
                $table->text('criteria');
                $table->text('cause')->nullable();
                $table->text('effect');
                $table->text('recommendation');
                $table->foreignId('accountable_owner_id')->constrained('users')->restrictOnDelete();
                $table->json('source_snapshot');
                $table->foreignId('raised_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('raised_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('audit_management_responses')) {
            Schema::create('audit_management_responses', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('audit_finding_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->string('position', 30);
                $table->text('response');
                $table->text('action_plan')->nullable();
                $table->date('target_date')->nullable();
                $table->json('finding_snapshot');
                $table->foreignId('responded_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('responded_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['audit_finding_id', 'version']);
            });
        }

        if (Schema::hasTable('audit_closeout_submissions') && ! Schema::hasColumn('audit_closeout_submissions', 'audit_finding_snapshots')) {
            Schema::table('audit_closeout_submissions', fn (Blueprint $table) => $table->json('audit_finding_snapshots')->nullable()->after('audit_effort_snapshots'));
        }
    }

    public function down(): void
    {
        // Retain governed finding, response, and closeout evidence during routine rollback.
    }
};
