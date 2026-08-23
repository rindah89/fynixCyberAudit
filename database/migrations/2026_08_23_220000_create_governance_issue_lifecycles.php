<?php

use App\Models\AiGovernanceIssue;
use App\Models\ControlTestFinding;
use App\Models\ResilienceIssue;
use App\Models\RiskGovernanceIssue;
use App\Models\VendorRiskIssue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('control_test_findings', 'remediation_task_id')) {
            Schema::table('control_test_findings', function (Blueprint $table): void {
                $table->foreignId('remediation_task_id')->nullable()->after('status')->constrained()->nullOnDelete();
            });
        }
        if (! Schema::hasTable('governance_issue_lifecycles')) {
            Schema::create('governance_issue_lifecycles', function (Blueprint $table): void {
                $table->id();
                $table->string('issue_type');
                $table->unsignedBigInteger('issue_id');
                $table->string('status')->default('open');
                $table->foreignId('remediation_task_id')->nullable()->constrained()->restrictOnDelete();
                $table->date('due_at')->nullable();
                $table->text('verification_summary')->nullable();
                $table->string('evidence_reference')->nullable();
                $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('verified_at')->nullable();
                $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();
                $table->unique(['issue_type', 'issue_id']);
            });
        }
        if (! Schema::hasTable('governance_issue_transitions')) {
            Schema::create('governance_issue_transitions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('governance_issue_lifecycle_id')->constrained()->restrictOnDelete();
                $table->string('from_status')->nullable();
                $table->string('to_status');
                $table->foreignId('transitioned_by')->constrained('users')->restrictOnDelete();
                $table->text('rationale');
                $table->unsignedBigInteger('remediation_task_id_snapshot')->nullable();
                $table->json('remediation_task_snapshot')->nullable();
                $table->text('verification_summary_snapshot')->nullable();
                $table->string('evidence_reference')->nullable();
                $table->timestamp('transitioned_at');
                $table->timestamps();
            });
        }

        foreach ([
            'risk_governance_issues' => RiskGovernanceIssue::class,
            'vendor_risk_issues' => VendorRiskIssue::class,
            'ai_governance_issues' => AiGovernanceIssue::class,
            'resilience_issues' => ResilienceIssue::class,
            'control_test_findings' => ControlTestFinding::class,
        ] as $table => $type) {
            DB::table($table)->orderBy('id')->each(function ($issue) use ($type): void {
                $now = now();
                $originalStatus = $issue->status;
                $status = 'open';
                $lifecycleId = DB::table('governance_issue_lifecycles')
                    ->where('issue_type', $type)->where('issue_id', $issue->id)->value('id');

                if (! $lifecycleId) {
                    if ($originalStatus !== $status) {
                        DB::table($table)->where('id', $issue->id)->update(['status' => $status, 'updated_at' => $now]);
                    }
                    $lifecycleId = DB::table('governance_issue_lifecycles')->insertGetId([
                        'issue_type' => $type, 'issue_id' => $issue->id, 'status' => $status,
                        'remediation_task_id' => $issue->remediation_task_id ?? null,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }

                if (DB::table('governance_issue_transitions')->where('governance_issue_lifecycle_id', $lifecycleId)->exists()) {
                    return;
                }

                DB::table('governance_issue_transitions')->insert([
                    'governance_issue_lifecycle_id' => $lifecycleId, 'from_status' => null,
                    'to_status' => $status, 'transitioned_by' => $issue->owner_id,
                    'rationale' => $originalStatus === $status
                        ? 'Existing issue registered during lifecycle migration; attribution uses the recorded issue owner.'
                        : "Existing issue registered during lifecycle migration and normalized from {$originalStatus} to open because independent closure evidence was unavailable; attribution uses the recorded issue owner.",
                    'remediation_task_id_snapshot' => $issue->remediation_task_id ?? null,
                    'transitioned_at' => $issue->created_at ?? $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
            });
        }
    }

    public function down(): void
    {
        // Governed lifecycle, transition, and remediation-link history is retained
        // during routine code rollback. A future migration may supersede this schema
        // additively, but rollback must not destroy suite audit or link state.
    }
};
