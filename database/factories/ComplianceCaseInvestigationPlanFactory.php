<?php

namespace Database\Factories;

use App\ComplianceCases\ComplianceCaseInvestigationPlanManager;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseInvestigationPlan;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

class ComplianceCaseInvestigationPlanFactory extends Factory
{
    protected $model = ComplianceCaseInvestigationPlan::class;

    public function definition(): array
    {
        return ['compliance_case_id' => ComplianceCase::factory()->state(['status' => ComplianceCaseStatus::Triaged, 'assigned_to' => User::factory()->afterCreating(fn (User $user) => $user->givePermissionTo('Investigate Compliance Cases')), 'triage_summary' => 'Factory triage.', 'investigation_planning_governed_at' => now()->startOfSecond()]),
            'version' => 1, 'objectives' => ['Establish material facts'], 'scope' => 'Defined factory investigation scope.',
            'procedures' => ['Inspect relevant records'], 'target_completion_at' => now()->addDays(14)->toDateString(),
            'authored_by' => fn (array $attributes) => ComplianceCase::query()->findOrFail($attributes['compliance_case_id'])->assigned_to,
            'author_snapshot' => [], 'case_snapshot' => [], 'rationale' => 'Factory governed investigation plan.',
            'submitted_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64)];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseInvestigationPlan $plan): void {
            $case = ComplianceCase::query()->findOrFail($plan->compliance_case_id);
            $event = ComplianceCaseEvent::query()->where('compliance_case_id', $case->id)->latest('version')->first()
                ?? ComplianceCaseEvent::factory()->for($case)->create();
            $plan->loadMissing('author');
            $manager = app(ComplianceCaseInvestigationPlanManager::class);
            $plan->author_snapshot = $plan->author->only(['id', 'name', 'email']);
            $plan->case_snapshot = $manager->caseSnapshot($case, $event);
            $plan->fingerprint = hash('sha256', CanonicalJson::encode($manager->planPayload($plan)));
        });
    }
}
