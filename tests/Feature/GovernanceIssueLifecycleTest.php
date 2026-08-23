<?php

namespace Tests\Feature;

use App\Enums\RiskDomain;
use App\Enums\RiskGovernanceDecision;
use App\Filament\Exports\GovernanceIssueLifecycleExporter;
use App\Filament\Resources\GovernanceIssueLifecycleResource;
use App\Models\GovernanceIssueLifecycle;
use App\Models\GovernanceIssueTransition;
use App\Models\Risk;
use App\Models\User;
use App\Remediation\Remediation;
use App\Services\RiskPortfolioManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GovernanceIssueLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.remediation', true);
    }

    public function test_issue_moves_through_attributable_remediation_and_independent_closure(): void
    {
        [$manager, $risk, $issue] = $this->riskIssue();
        $assignee = User::factory()->create();
        $project = app(Remediation::class)->createProject($manager, ['name' => 'Enterprise risk treatment']);
        $manager->revokePermissionTo('Manage Remediation');
        Sanctum::actingAs($manager);

        $this->assertSame('open', $issue->lifecycle->status->value);
        $this->assertSame($manager->id, $issue->lifecycle->transitions->first()->transitioned_by);
        $response = $this->postJson("/api/governance-issues/risk/{$issue->id}/remediation", [
            'remediation_project_id' => $project->id,
            'assignee_id' => $assignee->id,
            'priority' => 'High',
            'due_date' => now()->addMonth()->toDateString(),
            'rationale' => 'Treat the exposure through the governed remediation plan.',
        ])->assertCreated()->assertJsonPath('data.status', 'in_remediation')
            ->assertJsonPath('data.issue.status', 'in_remediation');
        $taskId = $response->json('data.remediation_task.id');
        $this->assertDatabaseHas('remediation_tasks', ['id' => $taskId, 'assignee_id' => $assignee->id]);
        $this->assertSame('action_required', $risk->fresh()->portfolio_governance_status);

        $this->postJson("/api/governance-issues/risk/{$issue->id}/request-verification", [
            'rationale' => 'Treatment implementation is ready for independent review.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['remediation_task']);

        $task = $issue->fresh()->remediationTask;
        $manager->givePermissionTo('Manage Remediation');
        app(Remediation::class)->updateTaskStatus($manager, $task, 'Completed');
        $this->postJson("/api/governance-issues/risk/{$issue->id}/request-verification", [
            'rationale' => 'Treatment implementation is ready for independent review.',
        ])->assertOk()->assertJsonPath('data.status', 'verification');
        $this->assertSame('action_required', $risk->fresh()->portfolio_governance_status);

        $this->postJson("/api/governance-issues/risk/{$issue->id}/close", [
            'verification_summary' => 'The mitigating control operates as intended.',
            'evidence_reference' => 'OPERATOR-REF-2026-081',
        ])->assertForbidden();

        $verifier = User::factory()->create();
        $verifier->givePermissionTo('Verify Issue Closure');
        Sanctum::actingAs($verifier);
        $this->postJson("/api/governance-issues/risk/{$issue->id}/close", [
            'verification_summary' => 'The mitigating control operates as intended and addresses the reviewed exposure.',
            'evidence_reference' => 'OPERATOR-REF-2026-081',
        ])->assertOk()->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.verified_by', $verifier->id)
            ->assertJsonPath('data.issue.status', 'closed');
        $this->assertSame('mitigate', $risk->fresh()->portfolio_governance_status);
        $this->assertDatabaseHas('governance_issue_transitions', [
            'to_status' => 'closed', 'transitioned_by' => $verifier->id,
            'evidence_reference' => 'OPERATOR-REF-2026-081',
        ]);
    }

    public function test_closed_issue_can_be_reopened_while_history_remains_append_only(): void
    {
        [$manager, , $issue] = $this->riskIssue();
        $project = app(Remediation::class)->createProject($manager, ['name' => 'Risk action plan']);
        Sanctum::actingAs($manager);
        $taskId = $this->postJson("/api/governance-issues/risk/{$issue->id}/remediation", [
            'remediation_project_id' => $project->id, 'priority' => 'Medium',
            'due_date' => now()->addMonth()->toDateString(), 'rationale' => 'Create a tracked corrective action.',
        ])->assertCreated()->json('data.remediation_task.id');
        app(Remediation::class)->updateTaskStatus($manager, $issue->fresh()->remediationTask, 'Completed');
        $this->postJson("/api/governance-issues/risk/{$issue->id}/request-verification", ['rationale' => 'Ready for verification.'])->assertOk();
        $verifier = User::factory()->create();
        $verifier->givePermissionTo('Verify Issue Closure');
        Sanctum::actingAs($verifier);
        $this->postJson("/api/governance-issues/risk/{$issue->id}/close", ['verification_summary' => 'Verified independently.'])->assertOk();

        Sanctum::actingAs($manager);
        $this->postJson("/api/governance-issues/risk/{$issue->id}/reopen", ['rationale' => 'A regression invalidated the closure.'])
            ->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.issue.status', 'open');
        $this->assertDatabaseCount('governance_issue_transitions', 5);
        $this->assertDatabaseHas('governance_issue_transitions', ['to_status' => 'closed', 'remediation_task_id_snapshot' => $taskId]);

        $transition = GovernanceIssueTransition::query()->where('to_status', 'closed')->firstOrFail();
        $this->expectException(\LogicException::class);
        $transition->update(['rationale' => 'Rewritten history']);
    }

    public function test_owner_can_read_history_but_outsider_cannot_mutate_or_view(): void
    {
        [$manager, , $issue] = $this->riskIssue();
        Sanctum::actingAs($manager);
        $this->getJson("/api/governance-issues/risk/{$issue->id}")
            ->assertOk()->assertJsonPath('data.status', 'open')->assertJsonCount(1, 'data.transitions');

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->getJson("/api/governance-issues/risk/{$issue->id}")->assertForbidden();
        $this->postJson("/api/governance-issues/risk/{$issue->id}/reopen", ['rationale' => 'Unauthorized.'])->assertForbidden();
    }

    public function test_source_issue_status_and_remediation_link_cannot_bypass_the_lifecycle(): void
    {
        [, , $issue] = $this->riskIssue();

        try {
            $issue->update(['status' => 'closed']);
            $this->fail('Direct status mutation should be rejected.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('governed lifecycle', $exception->getMessage());
        }

        $this->expectException(\LogicException::class);
        $issue->delete();
    }

    public function test_scoped_workspace_and_export_expose_current_state_without_lazy_loading(): void
    {
        [$manager, , $issue] = $this->riskIssue();
        $this->actingAs($manager)->get(GovernanceIssueLifecycleResource::getUrl('index'))->assertOk();
        $record = GovernanceIssueLifecycleResource::getEloquentQuery()->findOrFail($issue->lifecycle->id);
        $this->assertTrue($record->relationLoaded('issue'));
        $this->assertTrue($record->issue->relationLoaded('owner'));
        $this->assertTrue($record->relationLoaded('remediationTask'));

        $columns = collect(GovernanceIssueLifecycleExporter::getColumns())->map->getName();
        $this->assertContains('source_type', $columns);
        $this->assertContains('remediationTask.number', $columns);
        $this->assertContains('verification_summary', $columns);
        $exported = GovernanceIssueLifecycleExporter::modifyQuery(GovernanceIssueLifecycle::query()->whereKey($record))->firstOrFail();
        $this->assertTrue($exported->relationLoaded('issue'));
    }

    public function test_migration_retry_and_routine_rollback_preserve_governed_history(): void
    {
        [$manager, , $issue] = $this->riskIssue();
        $project = app(Remediation::class)->createProject($manager, ['name' => 'Migration-safe treatment']);
        Sanctum::actingAs($manager);
        $this->postJson("/api/governance-issues/risk/{$issue->id}/remediation", [
            'remediation_project_id' => $project->id, 'priority' => 'High',
            'due_date' => now()->addMonth()->toDateString(), 'rationale' => 'Preserve this governed handoff.',
        ])->assertCreated();
        $transitionCount = GovernanceIssueTransition::query()->count();

        $migration = require database_path('migrations/2026_08_23_220000_create_governance_issue_lifecycles.php');
        $migration->up();
        $this->assertSame('in_remediation', $issue->fresh()->lifecycle->status->value);
        $this->assertSame($transitionCount, GovernanceIssueTransition::query()->count());

        $migration->down();
        $this->assertTrue(Schema::hasTable('governance_issue_lifecycles'));
        $this->assertTrue(Schema::hasTable('governance_issue_transitions'));
        $this->assertDatabaseHas('governance_issue_lifecycles', ['issue_id' => $issue->id, 'status' => 'in_remediation']);
    }

    private function riskIssue(): array
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo(['Manage Risk Portfolio', 'Manage Remediation', 'Manage Issue Lifecycle']);
        $risk = Risk::factory()->create(['domain' => RiskDomain::Enterprise, 'residual_likelihood' => 4, 'residual_impact' => 4]);
        $risk->governanceProfile()->create([
            'owner_id' => $manager->id, 'appetite_threshold' => 8, 'review_frequency' => 'quarterly',
            'strategic_objective' => 'Protect enterprise value.', 'next_review_at' => now()->addQuarter(),
        ]);
        $review = app(RiskPortfolioManager::class)->review($risk, $manager, RiskGovernanceDecision::Mitigate, [
            'summary' => 'Risk treatment is required.', 'next_review_at' => now()->addQuarter(),
        ]);

        return [$manager, $risk, $review->issue];
    }
}
