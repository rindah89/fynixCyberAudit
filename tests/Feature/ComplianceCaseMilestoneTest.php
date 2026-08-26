<?php

namespace Tests\Feature;

use App\ComplianceCases\ComplianceCaseInvestigationPlanManager;
use App\ComplianceCases\ComplianceCaseInvestigationProcedureExecutionManager;
use App\ComplianceCases\ComplianceCaseInvestigationReportManager;
use App\ComplianceCases\ComplianceCaseManager;
use App\ComplianceCases\ComplianceCaseMilestoneManager;
use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCaseInvestigationPlanDecision;
use App\Enums\ComplianceCaseInvestigationProcedureResult;
use App\Enums\ComplianceCasePriority;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseMilestone;
use App\Models\User;
use App\Support\CanonicalJson;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ComplianceCaseMilestoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.compliance_cases', true);
    }

    public function test_required_milestone_blocks_closure_until_completed_or_waived(): void
    {
        [$case, $investigator, $closer] = $this->resolvedCase();
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $owner = User::factory()->create();
        $due = Carbon::parse('2026-09-01 00:00:00', 'UTC');
        Carbon::setTestNow($due->copy()->subDays(2));

        $id = $this->actingAs($manager)->postJson("/api/compliance-cases/{$case->id}/milestones", [
            'title' => 'Interview named participants',
            'description' => 'Complete the first interview before the due time.',
            'owner_id' => $owner->id,
            'due_at' => $due->toIso8601String(),
            'required' => true,
        ])->assertCreated()->json('data.id');
        $milestone = ComplianceCaseMilestone::query()->findOrFail($id);
        $this->assertSame(hash('sha256', CanonicalJson::encode(app(ComplianceCaseMilestoneManager::class)->payload($milestone))), $milestone->fingerprint);

        try {
            app(ComplianceCaseManager::class)->record($closer, $case->refresh(), [
                'status' => ComplianceCaseStatus::Closed->value,
                'closure_summary' => 'Cannot close with an open required milestone.',
                'summary' => 'Blocked closure.',
            ]);
            $this->fail('Expected an open required milestone to block closure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('milestones', $exception->errors());
        }

        $this->actingAs($owner)->postJson("/api/compliance-case-milestones/{$id}/complete", [
            'summary' => 'The interview was conducted.',
        ])->assertCreated();
        app(ComplianceCaseManager::class)->record($closer, $case->refresh(), [
            'status' => ComplianceCaseStatus::Closed->value,
            'closure_summary' => 'Required milestone is terminal.',
            'summary' => 'Close after completion.',
        ]);
        $this->assertSame(ComplianceCaseStatus::Closed, $case->fresh()->status);
        Carbon::setTestNow();
    }

    public function test_reconcile_retains_idempotent_due_soon_and_overdue_evidence(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $owner = User::factory()->create();
        $cases = app(ComplianceCaseManager::class);
        $milestones = app(ComplianceCaseMilestoneManager::class);
        $case = $cases->open($manager, [
            'title' => 'Due milestone', 'category' => ComplianceCaseCategory::Other->value,
            'priority' => ComplianceCasePriority::Medium->value, 'allegation' => 'A governed allegation.',
            'summary' => 'Open.',
        ]);
        $due = Carbon::parse('2026-09-10 12:00:00', 'UTC');
        Carbon::setTestNow($due->copy()->subDays(2));
        $milestone = $milestones->define($manager, $case, [
            'title' => 'Evidence pack', 'description' => 'Assemble the pack.', 'owner_id' => $owner->id,
            'due_at' => $due->toIso8601String(), 'required' => false,
        ]);
        $this->assertSame(1, $milestones->reconcile($due->copy()->subDays(2)));
        $this->assertSame(0, $milestones->reconcile($due->copy()->subDays(2)));
        $this->assertDatabaseHas('compliance_case_milestone_events', [
            'compliance_case_milestone_id' => $milestone->id, 'event_type' => 'due_soon',
        ]);
        $delivery = DB::table('compliance_case_milestone_deliveries')
            ->where('compliance_case_milestone_id', $milestone->id)
            ->where('event_type', 'due_soon')->first();
        $this->assertNotNull($delivery);
        $this->assertDatabaseHas('notifications', [
            'id' => $delivery->notification_id, 'notifiable_id' => $owner->id,
        ]);
        $this->actingAs($manager)->getJson("/api/compliance-cases/{$case->id}/milestones")
            ->assertOk()->assertJsonPath('data.0.deliveries.0.notification_id', $delivery->notification_id);
        DB::table('notifications')->where('id', $delivery->notification_id)->delete();
        $this->assertDatabaseHas('compliance_case_milestone_deliveries', ['id' => $delivery->id]);
        $this->assertSame(1, $milestones->reconcile($due->copy()->addSecond()));
        $this->assertDatabaseHas('compliance_case_milestone_events', [
            'compliance_case_milestone_id' => $milestone->id, 'event_type' => 'overdue',
        ]);
        $this->assertSame(2, DB::table('compliance_case_milestone_deliveries')
            ->where('compliance_case_milestone_id', $milestone->id)->count());
        Carbon::setTestNow();
    }

    public function test_cancelled_milestone_notification_rolls_back_event_and_delivery_for_retry(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $owner = User::factory()->create();
        $case = app(ComplianceCaseManager::class)->open($manager, [
            'title' => 'Retry milestone', 'category' => ComplianceCaseCategory::Other->value,
            'priority' => ComplianceCasePriority::Low->value, 'allegation' => 'A governed allegation.',
            'summary' => 'Open.',
        ]);
        $milestone = app(ComplianceCaseMilestoneManager::class)->define($manager, $case, [
            'title' => 'Retry delivery', 'description' => 'Retry atomically.', 'owner_id' => $owner->id,
            'due_at' => now()->addDay()->toIso8601String(), 'required' => false,
        ]);
        Event::listen(NotificationSending::class, fn (): bool => false);
        $this->assertThrows(fn () => app(ComplianceCaseMilestoneManager::class)->reconcile(now()), \LogicException::class);
        Event::forget(NotificationSending::class);
        $this->assertSame(0, $milestone->events()->count());
        $this->assertSame(0, $milestone->deliveries()->count());
        $this->assertSame(1, app(ComplianceCaseMilestoneManager::class)->reconcile(now()));
        $this->assertSame(1, $milestone->events()->count());
        $this->assertSame(1, $milestone->deliveries()->count());
    }

    public function test_scheduled_artisan_command_records_idempotent_due_soon_and_overdue_events(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $owner = User::factory()->create();
        $case = app(ComplianceCaseManager::class)->open($manager, [
            'title' => 'Scheduled milestone', 'category' => ComplianceCaseCategory::Other->value,
            'priority' => ComplianceCasePriority::Medium->value, 'allegation' => 'A governed allegation.',
            'summary' => 'Open.',
        ]);
        $due = Carbon::parse('2026-09-10 12:00:00', 'UTC');
        Carbon::setTestNow($due->copy()->subDays(2));
        $milestone = app(ComplianceCaseMilestoneManager::class)->define($manager, $case, [
            'title' => 'Evidence pack', 'description' => 'Assemble the pack.', 'owner_id' => $owner->id,
            'due_at' => $due->toIso8601String(), 'required' => false,
        ]);
        $this->artisan('fynix:reconcile-compliance-case-milestones')->assertSuccessful();
        $this->assertDatabaseHas('compliance_case_milestone_events', [
            'compliance_case_milestone_id' => $milestone->id, 'event_type' => 'due_soon',
        ]);
        $this->artisan('fynix:reconcile-compliance-case-milestones')->assertSuccessful();
        $this->assertSame(1, $milestone->events()->where('event_type', 'due_soon')->count());
        Carbon::setTestNow($due->copy()->addSecond());
        $this->artisan('fynix:reconcile-compliance-case-milestones')->assertSuccessful();
        $this->assertDatabaseHas('compliance_case_milestone_events', [
            'compliance_case_milestone_id' => $milestone->id, 'event_type' => 'overdue',
        ]);
        $this->artisan('fynix:reconcile-compliance-case-milestones')->assertSuccessful();
        $this->assertSame(1, $milestone->events()->where('event_type', 'overdue')->count());
        $scheduled = collect(app(Schedule::class)->events())
            ->contains(fn ($event): bool => str_contains((string) $event->command, 'fynix:reconcile-compliance-case-milestones'));
        $this->assertTrue($scheduled);
        Carbon::setTestNow();
    }

    public function test_recused_owner_cannot_complete_or_waive_a_milestone(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $reviewer = User::factory()->create();
        $reviewer->assignRole('Security Admin');
        $owner = User::factory()->create();
        $waver = User::factory()->create();
        $waver->assignRole('Security Admin');
        $case = app(ComplianceCaseManager::class)->open($manager, [
            'title' => 'Recused milestone', 'category' => ComplianceCaseCategory::Other->value,
            'priority' => ComplianceCasePriority::Low->value, 'allegation' => 'A governed allegation.',
            'summary' => 'Open.',
        ]);
        $completeId = $this->actingAs($manager)->postJson("/api/compliance-cases/{$case->id}/milestones", [
            'title' => 'Owner work', 'description' => 'Owner must complete.', 'owner_id' => $owner->id,
            'due_at' => now()->addDay()->toIso8601String(), 'required' => false,
        ])->assertCreated()->json('data.id');
        $waiveId = $this->actingAs($manager)->postJson("/api/compliance-cases/{$case->id}/milestones", [
            'title' => 'Manager work', 'description' => 'A separated manager may waive.', 'owner_id' => $owner->id,
            'due_at' => now()->addDay()->toIso8601String(), 'required' => false,
        ])->assertCreated()->json('data.id');

        $ownerDeclaration = $this->actingAs($manager)->postJson("/api/compliance-cases/{$case->id}/conflicts", [
            'subject_user_id' => $owner->id, 'nature' => 'Owner conflict.', 'rationale' => 'Recuse the owner.',
        ])->assertCreated()->json('data.id');
        $this->actingAs($reviewer)->postJson("/api/compliance-case-conflicts/{$ownerDeclaration}/decision", [
            'decision' => 'confirmed', 'summary' => 'Owner is recused.',
        ])->assertCreated();
        $this->actingAs($owner)->postJson("/api/compliance-case-milestones/{$completeId}/complete", [
            'summary' => 'Recused owner must not complete.',
        ])->assertForbidden();

        $waverDeclaration = $this->actingAs($manager)->postJson("/api/compliance-cases/{$case->id}/conflicts", [
            'subject_user_id' => $waver->id, 'nature' => 'Waver conflict.', 'rationale' => 'Recuse the waver.',
        ])->assertCreated()->json('data.id');
        $this->actingAs($reviewer)->postJson("/api/compliance-case-conflicts/{$waverDeclaration}/decision", [
            'decision' => 'confirmed', 'summary' => 'Waver is recused.',
        ])->assertCreated();
        $this->actingAs($waver)->postJson("/api/compliance-case-milestones/{$waiveId}/waive", [
            'summary' => 'Recused manager must not waive.',
        ])->assertForbidden();
    }

    public function test_canonical_milestone_factory_reconstructs_production_fingerprint(): void
    {
        $milestone = ComplianceCaseMilestone::factory()->create();
        $this->assertSame(
            hash('sha256', CanonicalJson::encode(app(ComplianceCaseMilestoneManager::class)->payload($milestone))),
            $milestone->fingerprint,
        );
        $this->assertTrue($milestone->due_at->isFuture());
    }

    /**
     * @return array{0:ComplianceCase,1:User,2:User}
     */
    private function resolvedCase(): array
    {
        $opener = User::factory()->create();
        $opener->assignRole('Security Admin');
        $investigator = User::factory()->create();
        $investigator->givePermissionTo('Investigate Compliance Cases');
        $closer = User::factory()->create();
        $closer->assignRole('Security Admin');
        $cases = app(ComplianceCaseManager::class);
        $case = $cases->open($opener, [
            'title' => 'Milestone case', 'category' => ComplianceCaseCategory::Other->value,
            'priority' => ComplianceCasePriority::High->value, 'allegation' => 'A governed allegation.',
            'summary' => 'Open.',
        ]);
        $cases->record($opener, $case, [
            'status' => ComplianceCaseStatus::Triaged->value, 'assigned_to' => $investigator->id,
            'triage_summary' => 'Investigate.', 'summary' => 'Assign.',
        ]);
        $plans = app(ComplianceCaseInvestigationPlanManager::class);
        $plan = $plans->submit($investigator, $case->refresh(), [
            'objectives' => ['Facts'], 'scope' => 'Records.', 'procedures' => ['Inspect records'],
            'target_completion_at' => now()->addMonth()->toDateString(), 'rationale' => 'Plan.',
        ]);
        $plans->review($opener, $plan, [
            'decision' => ComplianceCaseInvestigationPlanDecision::Approved->value, 'summary' => 'Approved.',
        ]);
        $cases->record($investigator, $case->refresh(), [
            'status' => ComplianceCaseStatus::Investigating->value,
            'investigation_summary' => 'Working.', 'summary' => 'Investigate.',
        ]);
        $executions = app(ComplianceCaseInvestigationProcedureExecutionManager::class);
        $execution = $executions->record($investigator, $case->refresh(), [
            'procedure_index' => 1, 'result' => ComplianceCaseInvestigationProcedureResult::Completed->value,
            'summary' => 'Done.',
        ]);
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('Manage Compliance Cases');
        $executions->review($reviewer, $execution, ['decision' => 'approved', 'summary' => 'Approved.']);
        $reports = app(ComplianceCaseInvestigationReportManager::class);
        $report = $reports->submit($investigator, $case->refresh(), [
            'outcome' => 'substantiated', 'executive_summary' => 'Done.', 'analysis' => 'Analysis.',
            'findings' => 'Findings.', 'recommendations' => 'Recommendations.',
        ]);
        $reportReviewer = User::factory()->create();
        $reportReviewer->givePermissionTo('Manage Compliance Cases');
        $reports->review($reportReviewer, $report, ['decision' => 'approved', 'summary' => 'Approved.']);
        $cases->record($investigator, $case->refresh(), [
            'status' => ComplianceCaseStatus::Resolved->value,
            'resolution_summary' => 'Resolved.', 'summary' => 'Resolve.',
        ]);

        return [$case->refresh(), $investigator, $closer];
    }
}
