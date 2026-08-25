<?php

namespace Database\Factories;

use App\Models\ComplianceCaseInterview;
use App\Models\ComplianceCaseInterviewEvent;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

class ComplianceCaseInterviewEventFactory extends Factory
{
    protected $model = ComplianceCaseInterviewEvent::class;

    public function definition(): array
    {
        $recordedAt = now()->startOfSecond();

        return [
            'compliance_case_interview_id' => ComplianceCaseInterview::factory(), 'version' => 1,
            'after_snapshot' => function (array $attributes): array {
                $interview = ComplianceCaseInterview::query()->with(['complianceCase.opener:id,name,email', 'complianceCase.assignee:id,name,email', 'subjectUser:id,name,email', 'interviewer:id,name,email'])->findOrFail($attributes['compliance_case_interview_id']);

                return $interview->only(['id', 'compliance_case_id', 'status', 'scheduled_at', 'conducted_at', 'location', 'purpose', 'summary', 'cancellation_reason']) + [
                    'case' => $interview->complianceCase->only([
                        'id', 'number', 'title', 'category', 'priority', 'status', 'allegation', 'source_channel', 'source_reference',
                        'reporter_reference', 'confidential', 'due_at', 'triage_summary', 'investigation_summary', 'resolution_summary',
                        'closure_summary', 'opened_at', 'resolved_at', 'closed_at', 'governed_at',
                    ]) + [
                        'opened_by' => $interview->complianceCase->opener?->only(['id', 'name', 'email']),
                        'assigned_to' => $interview->complianceCase->assignee?->only(['id', 'name', 'email']),
                    ],
                    'subject' => $interview->subjectUser?->only(['id', 'name', 'email']),
                    'subject_reference' => $interview->subject_reference,
                    'interviewer' => $interview->interviewer?->only(['id', 'name', 'email']),
                ];
            },
            'event_type' => fn (array $attributes): string => (int) $attributes['version'] === 1 ? 'scheduled' : 'rescheduled',
            'before_snapshot' => fn (array $attributes): ?array => (int) $attributes['version'] === 1 ? null : $attributes['after_snapshot'],
            'rationale' => 'Factory governed interview event.',
            'recorded_by' => fn (array $attributes): int => (int) ComplianceCaseInterview::query()->findOrFail($attributes['compliance_case_interview_id'])->interviewer_id,
            'recorded_at' => $recordedAt,
            'fingerprint' => fn (array $attributes): string => hash('sha256', CanonicalJson::encode([
                'compliance_case_interview_id' => $attributes['compliance_case_interview_id'], 'version' => $attributes['version'],
                'event_type' => $attributes['event_type'], 'before_snapshot' => $attributes['before_snapshot'],
                'after_snapshot' => $attributes['after_snapshot'], 'rationale' => $attributes['rationale'],
                'recorded_by' => $attributes['recorded_by'], 'recorded_at' => $attributes['recorded_at']->toIso8601String(),
            ])),
        ];
    }
}
