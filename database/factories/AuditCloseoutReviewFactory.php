<?php

namespace Database\Factories;

use App\Enums\AuditCloseoutDecision;
use App\Models\AuditCloseoutReview;
use App\Models\AuditCloseoutSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditCloseoutReviewFactory extends Factory
{
    protected $model = AuditCloseoutReview::class;

    public function definition(): array
    {
        return [
            'audit_closeout_submission_id' => AuditCloseoutSubmission::factory(),
            'decision' => AuditCloseoutDecision::Rejected,
            'review_summary' => fake()->paragraph(),
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
            'report_snapshot' => function (array $attributes): array {
                $submission = AuditCloseoutSubmission::query()->findOrFail($attributes['audit_closeout_submission_id']);

                return [
                    'audit_id' => $submission->audit_id, 'submission_id' => $submission->id, 'submission_version' => $submission->version,
                    'submission_fingerprint' => $submission->fingerprint, 'opinion' => $submission->opinion->value,
                    'executive_summary' => $submission->executive_summary, 'scope_limitations' => $submission->scope_limitations,
                    'significant_matters' => $submission->significant_matters, 'recommendations_summary' => $submission->recommendations_summary,
                    'audit_snapshot' => $submission->audit_snapshot, 'engagement_baseline_snapshot' => $submission->engagement_baseline_snapshot,
                    'audit_item_snapshots' => $submission->audit_item_snapshots, 'data_request_snapshots' => $submission->data_request_snapshots,
                    'audit_procedure_snapshots' => $submission->audit_procedure_snapshots,
                    'audit_effort_snapshots' => $submission->audit_effort_snapshots,
                    'audit_finding_snapshots' => $submission->audit_finding_snapshots,
                    'decision' => AuditCloseoutDecision::Rejected->value, 'review_summary' => $attributes['review_summary'],
                    'reviewed_by' => $attributes['reviewed_by'], 'reviewed_at' => $attributes['reviewed_at']->toIso8601String(),
                ];
            },
            'report_disk' => null, 'report_path' => null, 'report_size' => null, 'report_sha256' => null,
            'fingerprint' => fn (array $attributes): string => hash('sha256', json_encode($attributes['report_snapshot'] + ['report_disk' => null, 'report_path' => null, 'report_size' => null, 'report_sha256' => null], JSON_THROW_ON_ERROR)),
        ];
    }
}
