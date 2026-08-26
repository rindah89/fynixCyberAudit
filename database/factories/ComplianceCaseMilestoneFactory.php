<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseInvestigationPlanManager;
use App\ComplianceCases\ComplianceCaseMilestoneManager;
use App\Enums\ComplianceCaseMilestoneStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseMilestone;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ComplianceCaseMilestone> */
class ComplianceCaseMilestoneFactory extends Factory
{
    protected $model = ComplianceCaseMilestone::class;

    public function definition(): array
    {
        $definer = User::factory()->create();
        $definer->assignRole('Security Admin');
        $owner = User::factory()->create();
        $case = ComplianceCase::factory()->create(['opened_by' => $definer->id]);
        $event = ComplianceCaseEvent::factory()->create(['compliance_case_id' => $case->id, 'recorded_by' => $definer->id]);
        $definedAt = now()->startOfSecond();

        return [
            'compliance_case_id' => $case->id, 'compliance_case_event_id' => $event->id, 'version' => 1,
            'title' => 'Factory governed milestone', 'description' => 'Factory bounded milestone evidence.',
            'owner_id' => $owner->id, 'owner_snapshot' => $owner->only(['id', 'name', 'email']),
            'due_at' => $definedAt->copy()->addWeek(), 'required' => true,
            'status' => ComplianceCaseMilestoneStatus::Open, 'defined_by' => $definer->id,
            'definer_snapshot' => $definer->only(['id', 'name', 'email']),
            'case_snapshot' => app(ComplianceCaseInvestigationPlanManager::class)->caseSnapshot($case, $event),
            'defined_at' => $definedAt, 'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseMilestone $milestone): void {
            $milestone->fingerprint = hash('sha256', CanonicalJson::encode(
                app(ComplianceCaseMilestoneManager::class)->payload($milestone),
            ));
        });
    }
}
