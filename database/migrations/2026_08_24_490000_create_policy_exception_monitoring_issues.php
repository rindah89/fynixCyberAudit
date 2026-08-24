<?php

use App\Models\PolicyExceptionMonitoringIssue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('policy_exception_monitoring_issues')) {
            Schema::create('policy_exception_monitoring_issues', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('policy_exception_monitoring_review_id')->unique()->constrained()->restrictOnDelete();
                $table->foreignId('policy_exception_id')->constrained()->restrictOnDelete();
                $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
                $table->string('title');
                $table->text('description');
                $table->string('severity');
                $table->string('status')->default('open');
                $table->foreignId('remediation_task_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamps();
            });
        }

        DB::table('policy_exception_monitoring_reviews as reviews')
            ->join('policy_exceptions as exceptions', 'exceptions.id', '=', 'reviews.policy_exception_id')
            ->join('policies', 'policies.id', '=', 'exceptions.policy_id')
            ->whereIn('reviews.outcome', ['needs_action', 'revoke_recommended'])
            ->orderBy('reviews.id')
            ->select([
                'reviews.id as review_id', 'reviews.policy_exception_id', 'reviews.outcome',
                'reviews.review_summary', 'reviews.control_effectiveness', 'reviews.reviewed_by',
                'reviews.reviewed_at', 'exceptions.name as exception_name', 'exceptions.expiration_date',
                'policies.owner_id',
            ])->each(function ($row): void {
                DB::transaction(function () use ($row): void {
                    $now = now();
                    $issueId = DB::table('policy_exception_monitoring_issues')
                        ->where('policy_exception_monitoring_review_id', $row->review_id)->value('id');
                    if (! $issueId) {
                        $issueId = DB::table('policy_exception_monitoring_issues')->insertGetId([
                            'policy_exception_monitoring_review_id' => $row->review_id,
                            'policy_exception_id' => $row->policy_exception_id,
                            'owner_id' => $row->owner_id,
                            'title' => "Policy exception {$row->exception_name} requires action",
                            'description' => $row->review_summary."\n\nCompensating-control assessment: ".$row->control_effectiveness,
                            'severity' => $row->outcome === 'revoke_recommended' ? 'high' : 'medium',
                            'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }

                    $lifecycleId = DB::table('governance_issue_lifecycles')
                        ->where('issue_type', PolicyExceptionMonitoringIssue::class)->where('issue_id', $issueId)->value('id');
                    if (! $lifecycleId) {
                        $lifecycleId = DB::table('governance_issue_lifecycles')->insertGetId([
                            'issue_type' => PolicyExceptionMonitoringIssue::class,
                            'issue_id' => $issueId, 'status' => 'open',
                            'due_at' => $row->expiration_date, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }

                    if (! DB::table('governance_issue_transitions')->where('governance_issue_lifecycle_id', $lifecycleId)->exists()) {
                        DB::table('governance_issue_transitions')->insert([
                            'governance_issue_lifecycle_id' => $lifecycleId,
                            'from_status' => null, 'to_status' => 'open',
                            'transitioned_by' => $row->reviewed_by,
                            'rationale' => 'Existing action-required policy exception review registered during lifecycle migration.',
                            'transitioned_at' => $row->reviewed_at, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                });
            });
    }

    public function down(): void
    {
        // Governed issue and remediation-link history is retained during routine rollback.
    }
};
