<?php

namespace Tests\Feature;

use App\ComplianceCases\ComplianceCaseInvestigationPlanManager;
use App\ComplianceCases\ComplianceCaseManager;
use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCaseInvestigationPlanDecision;
use App\Enums\ComplianceCasePriority;
use App\Enums\ComplianceCaseStatus;
use App\Filament\Resources\ComplianceCaseResource\Pages\ViewComplianceCase;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\InvestigationPlansRelationManager;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseInvestigationPlan;
use App\Models\ComplianceCaseInvestigationPlanReview;
use App\Models\User;
use App\Support\CanonicalJson;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ComplianceCaseInvestigationPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.compliance_cases', true);
    }

    public function test_current_independently_approved_plan_gates_investigation(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $investigator = User::factory()->create();
        $investigator->givePermissionTo('Investigate Compliance Cases');
        $reviewer = User::factory()->create();
        $reviewer->assignRole('Security Admin');
        $outsider = User::factory()->create();
        $cases = app(ComplianceCaseManager::class);
        $plans = app(ComplianceCaseInvestigationPlanManager::class);
        $case = $cases->open($manager, [
            'title' => 'Planned investigation', 'category' => ComplianceCaseCategory::Other->value,
            'priority' => ComplianceCasePriority::High->value, 'allegation' => 'A governed allegation.',
            'source_channel' => 'Authenticated intake', 'summary' => 'Open the case.',
        ]);
        $cases->record($manager, $case, [
            'status' => ComplianceCaseStatus::Triaged->value, 'assigned_to' => $investigator->id,
            'triage_summary' => 'Fact finding is required.', 'summary' => 'Assign the investigator.',
        ]);
        try {
            $cases->record($investigator, $case->refresh(), ['status' => ComplianceCaseStatus::Investigating->value, 'summary' => 'Start without a plan.']);
            $this->fail('Expected an approved current plan gate.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('investigation_plan', $exception->errors());
        }
        $plan = $plans->submit($investigator, $case->refresh(), [
            'objectives' => ['Establish the relevant facts', 'Assess applicable policy obligations'],
            'scope' => 'Supplier-selection conduct and directly related records.',
            'procedures' => ['Interview the named participants', 'Inspect the retained procurement records'],
            'target_completion_at' => now()->addDays(14)->toDateString(),
            'rationale' => 'Define a bounded, attributable investigation approach.',
        ]);
        $plan = $plan->fresh();
        $this->assertSame(hash('sha256', CanonicalJson::encode($plans->planPayload($plan))), $plan->fingerprint);
        $retainedEvent = $plan->case_snapshot['event'];
        $sourceEvent = ComplianceCaseEvent::query()->findOrFail($retainedEvent['id']);
        $this->assertSame($case->id, $retainedEvent['compliance_case_id']);
        $this->assertSame($sourceEvent->before_snapshot, $retainedEvent['before_snapshot']);
        $this->assertSame($sourceEvent->after_snapshot, $retainedEvent['after_snapshot']);
        $this->assertSame($sourceEvent->summary, $retainedEvent['summary']);
        $this->assertSame($sourceEvent->recorded_at->toIso8601String(), $retainedEvent['recorded_at']);
        foreach ([$investigator, $outsider] as $actor) {
            try {
                $plans->review($actor, $plan, ['decision' => ComplianceCaseInvestigationPlanDecision::Approved->value, 'summary' => 'Unauthorized approval.']);
                $this->fail('Expected separated manager review authorization.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $cases->record($manager, $case->refresh(), [
            'due_at' => now()->addDays(20), 'summary' => 'Change the governed case context after plan submission.',
        ]);
        try {
            $plans->review($reviewer, $plan, [
                'decision' => ComplianceCaseInvestigationPlanDecision::Approved->value, 'summary' => 'Stale approval must fail.',
            ]);
            $this->fail('Expected stale plan approval to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('plan', $exception->errors());
        }
        $rejection = $plans->review($reviewer, $plan, [
            'decision' => ComplianceCaseInvestigationPlanDecision::Rejected->value,
            'summary' => 'The first plan requires a narrower scope.',
        ]);
        $this->assertSame(ComplianceCaseInvestigationPlanDecision::Rejected, $rejection->decision);
        try {
            $cases->record($investigator, $case->refresh(), ['status' => ComplianceCaseStatus::Investigating->value, 'summary' => 'Rejected plan cannot start.']);
            $this->fail('Expected rejected-plan gate.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('investigation_plan', $exception->errors());
        }
        $this->actingAs($investigator)->postJson("/api/compliance-cases/{$case->id}/investigation-plans", [
            'objectives' => ['Invalid server field'], 'scope' => 'Invalid.', 'procedures' => ['Invalid'],
            'target_completion_at' => now()->addDays(10)->toDateString(), 'rationale' => 'Invalid.', 'version' => 99,
        ])->assertUnprocessable();
        $this->actingAs($investigator)->postJson("/api/compliance-cases/{$case->id}/investigation-plans", [
            'objectives' => ['Establish the relevant facts'], 'scope' => 'Supplier-selection conduct only.',
            'procedures' => ['Interview the named participants'], 'target_completion_at' => now()->addDays(10)->toDateString(),
            'rationale' => 'Replace the rejected plan with a proportionate scope.',
        ])->assertCreated();
        $plan = ComplianceCaseInvestigationPlan::query()->where('compliance_case_id', $case->id)->orderByDesc('version')->firstOrFail();
        $review = $plans->review($reviewer, $plan, [
            'decision' => ComplianceCaseInvestigationPlanDecision::Approved->value,
            'summary' => 'The objectives, scope, procedures, ownership, and target are proportionate.',
        ]);
        $review = $review->fresh();
        $this->assertSame($plan->fingerprint, $review->plan_snapshot['fingerprint']);
        $this->assertSame(hash('sha256', CanonicalJson::encode($plans->reviewPayload($review))), $review->fingerprint);
        $cases->record($investigator, $case->refresh(), [
            'status' => ComplianceCaseStatus::Investigating->value, 'investigation_summary' => 'Approved plan execution started.',
            'summary' => 'Begin the independently approved current plan.',
        ]);
        $this->assertSame(ComplianceCaseStatus::Investigating, $case->refresh()->status);

        $this->actingAs($manager)->getJson("/api/compliance-cases/{$case->id}/investigation-plans")->assertOk()
            ->assertJsonPath('data.1.review.fingerprint', $review->fingerprint)
            ->assertJsonPath('data.1.objectives.0', 'Establish the relevant facts');
        $this->actingAs($manager)->getJson("/api/compliance-cases/{$case->id}")->assertOk()
            ->assertJsonPath('data.investigation_planning_governance_status', 'governed');
        $this->actingAs($outsider)->getJson("/api/compliance-cases/{$case->id}/investigation-plans")->assertForbidden();
        Livewire::actingAs($manager)->test(InvestigationPlansRelationManager::class, [
            'ownerRecord' => $case, 'pageClass' => ViewComplianceCase::class,
        ])->assertCanSeeTableRecords([$plan])->assertSee($review->fingerprint)->mountTableAction('inspect', $plan);
        $operatorEvidence = view('filament.compliance-case-investigation-plan', ['plan' => $plan->fresh()->load(['author', 'review.reviewer'])])->render();
        $this->assertStringContainsString($review->summary, $operatorEvidence);
        $this->assertStringContainsString($plan->scope, $operatorEvidence);
    }

    public function test_rejected_stale_bounds_factory_and_retained_migration_are_governed(): void
    {
        $plan = ComplianceCaseInvestigationPlan::factory()->create();
        $service = app(ComplianceCaseInvestigationPlanManager::class);
        $this->assertSame(hash('sha256', CanonicalJson::encode($service->planPayload($plan))), $plan->fingerprint);
        $factoryReview = ComplianceCaseInvestigationPlanReview::factory()->create();
        $this->assertSame(hash('sha256', CanonicalJson::encode($service->reviewPayload($factoryReview))), $factoryReview->fingerprint);
        try {
            $plan->scope = 'Mutation';
            $plan->save();
            $this->fail('Expected plan immutability.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('compliance_case_investigation_plans', ['id' => $plan->id, 'fingerprint' => $plan->fingerprint]);
        }
        try {
            $plan->delete();
            $this->fail('Expected plan retention.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('compliance_case_investigation_plans', ['id' => $plan->id]);
        }
        try {
            $factoryReview->summary = 'Mutation';
            $factoryReview->save();
            $this->fail('Expected review immutability.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('compliance_case_investigation_plan_reviews', ['id' => $factoryReview->id, 'fingerprint' => $factoryReview->fingerprint]);
        }
        try {
            $factoryReview->delete();
            $this->fail('Expected review retention.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('compliance_case_investigation_plan_reviews', ['id' => $factoryReview->id]);
        }
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $author = User::factory()->create();
        $author->givePermissionTo('Investigate Compliance Cases');
        $boundedCase = app(ComplianceCaseManager::class)->open($manager, [
            'title' => 'Bounded planning', 'category' => ComplianceCaseCategory::Other->value, 'priority' => ComplianceCasePriority::Medium->value,
            'allegation' => 'Bound test.', 'summary' => 'Open bound test.',
        ]);
        app(ComplianceCaseManager::class)->record($manager, $boundedCase, [
            'status' => ComplianceCaseStatus::Triaged->value, 'assigned_to' => $author->id, 'triage_summary' => 'Bound triage.', 'summary' => 'Assign.',
        ]);
        for ($version = 1; $version <= 20; $version++) {
            $boundedPlan = $service->submit($author, $boundedCase->refresh(), [
                'objectives' => ["Objective {$version}"], 'scope' => "Scope {$version}.", 'procedures' => ["Procedure {$version}"],
                'target_completion_at' => now()->addMonth()->toDateString(), 'rationale' => "Plan {$version}.",
            ]);
            $service->review($manager, $boundedPlan, [
                'decision' => ComplianceCaseInvestigationPlanDecision::Rejected->value, 'summary' => "Retain terminal review {$version}.",
            ]);
        }
        try {
            $service->submit($author, $boundedCase, [
                'objectives' => ['Plan 21'], 'scope' => 'Bound.', 'procedures' => ['Review'],
                'target_completion_at' => now()->addDay()->toDateString(), 'rationale' => 'Must fail.',
            ]);
            $this->fail('Expected exact 20-plan bound.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('case', $exception->errors());
        }
        $this->assertSame(20, $boundedCase->investigationPlans()->count());

        $legacyInvestigator = User::factory()->create();
        $legacyInvestigator->givePermissionTo('Investigate Compliance Cases');
        $legacy = ComplianceCase::factory()->create(['status' => ComplianceCaseStatus::Triaged, 'assigned_to' => $legacyInvestigator->id,
            'triage_summary' => 'Legacy triage.', 'investigation_planning_governed_at' => null]);
        $event = $legacy->events()->create(ComplianceCaseEvent::factory()->make(['compliance_case_id' => $legacy->id])->getAttributes());
        $this->assertNotNull($event);
        app(ComplianceCaseManager::class)->record($legacyInvestigator, $legacy, [
            'status' => ComplianceCaseStatus::Investigating->value, 'summary' => 'Legacy boundary transition.',
        ]);
        $this->assertSame(ComplianceCaseStatus::Investigating, $legacy->refresh()->status);
        $this->actingAs($manager)->getJson("/api/compliance-cases/{$legacy->id}")->assertOk()
            ->assertJsonPath('data.investigation_planning_governance_status', 'legacy');

        $migration = require database_path('migrations/2026_08_25_140000_create_compliance_case_investigation_plans.php');
        $migration->up();
        $migration->down();
        $this->assertTrue(Schema::hasTable('compliance_case_investigation_plans'));
        $this->assertDatabaseHas('compliance_case_investigation_plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('compliance_case_investigation_plan_reviews', ['id' => $factoryReview->id]);
    }
}
