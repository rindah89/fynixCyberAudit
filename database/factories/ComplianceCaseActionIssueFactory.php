<?php

namespace Database\Factories;

use App\Enums\ComplianceCasePriority;
use App\Enums\ComplianceCaseStatus;
use App\Enums\GovernanceIssueStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseActionIssue;
use App\Models\ComplianceCaseEvent;
use App\Models\User;
use App\Services\GovernanceIssueLifecycleManager;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

class ComplianceCaseActionIssueFactory extends Factory
{
    protected $model = ComplianceCaseActionIssue::class;

    public function configure(): static
    {
        return $this->afterCreating(function (ComplianceCaseActionIssue $issue): void {
            app(GovernanceIssueLifecycleManager::class)->register($issue, $issue->opener);
        });
    }

    public function definition(): array
    {
        $openedAt = now()->startOfSecond();
        $case = ComplianceCase::factory()->create([
            'status' => ComplianceCaseStatus::ActionRequired,
            'priority' => ComplianceCasePriority::High,
            'assigned_to' => fn () => User::factory()->create()->id,
            'investigation_summary' => 'Factory investigation conclusion requiring governed action.',
        ]);
        $event = ComplianceCaseEvent::factory()->create([
            'compliance_case_id' => $case->id, 'version' => 2, 'event_type' => 'action_required',
            'recorded_by' => $case->opened_by,
        ]);
        $sourceSnapshot = [
            'case' => $event->after_snapshot,
            'event' => [
                'id' => $event->id, 'compliance_case_id' => $event->compliance_case_id, 'version' => $event->version,
                'event_type' => $event->event_type, 'before_snapshot' => $event->before_snapshot,
                'after_snapshot' => $event->after_snapshot, 'summary' => $event->summary,
                'recorded_by' => $event->recorded_by, 'recorded_at' => $event->recorded_at->toIso8601String(),
                'fingerprint' => $event->fingerprint,
            ],
        ];
        $payload = [
            'compliance_case_id' => $case->id, 'compliance_case_event_id' => $event->id,
            'owner_id' => $case->assigned_to, 'opened_by' => $event->recorded_by,
            'title' => "{$case->number}: action required", 'description' => $event->summary,
            'severity' => 'high',
            'source_snapshot' => $sourceSnapshot, 'opened_at' => $openedAt->toIso8601String(),
        ];

        return $payload + [
            'status' => GovernanceIssueStatus::Open->value,
            'fingerprint' => hash('sha256', CanonicalJson::encode($payload)),
        ];
    }
}
