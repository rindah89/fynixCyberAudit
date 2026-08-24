<?php

namespace Tests\Feature;

use App\Filament\Exports\PolicyRevisionExporter;
use App\Filament\Resources\PolicyResource\Pages\ViewPolicy;
use App\Filament\Resources\PolicyResource\RelationManagers\RevisionsRelationManager;
use App\Models\Policy;
use App\Models\PolicyRevision;
use App\Models\PolicyRevisionReview;
use App\Models\Risk;
use App\Models\User;
use App\PolicyCompliance\PolicyRevisionContextManager;
use App\PolicyCompliance\PolicyRevisionManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PolicyRevisionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_owner_submits_immutable_policy_revision_and_independent_editor_approves_it(): void
    {
        $owner = User::factory()->create();
        $owner->givePermissionTo('Update Policies');
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('Update Policies');
        $policy = Policy::factory()->create([
            'owner_id' => $owner->id,
            'name' => 'Information Security Policy',
            'purpose' => 'Define the information-security governance direction.',
            'body' => 'All privileged access must be reviewed quarterly.',
            'effective_date' => now()->subYear()->toDateString(),
        ]);
        $risk = Risk::factory()->create();
        app(PolicyRevisionContextManager::class)->attachRisk($policy, $risk);

        Sanctum::actingAs($owner);
        $revision = $this->postJson("/api/policies/{$policy->id}/revisions", [
            'change_summary' => 'Formalize the quarterly privileged-access review requirement.',
            'proposed_effective_date' => now()->addMonth()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.policy_snapshot.body', 'All privileged access must be reviewed quarterly.')
            ->assertJsonPath('data.policy_snapshot.risks.0.id', $risk->id)
            ->assertJsonPath('data.submitted_by', $owner->id)
            ->json('data');

        Sanctum::actingAs($reviewer);
        $approved = $this->postJson("/api/policy-revisions/{$revision['id']}/review", [
            'decision' => 'approved',
            'review_summary' => 'The revision is complete, accountable, and ready to become effective.',
        ])->assertCreated()
            ->assertJsonPath('data.reviewed_by', $reviewer->id)
            ->assertJsonPath('data.revision.status', 'approved')
            ->json('data');

        $this->assertNotSame($owner->id, $approved['reviewed_by']);
        $this->assertDatabaseHas('policy_revisions', ['id' => $revision['id'], 'status' => 'approved']);
        $this->assertDatabaseHas('policy_revision_reviews', ['policy_revision_id' => $revision['id'], 'decision' => 'approved']);
        $this->assertSame('approved_scheduled', $policy->fresh()->revision_governance_status);
    }

    public function test_review_is_independent_current_and_terminal_while_history_is_paginated(): void
    {
        $owner = User::factory()->create();
        $owner->givePermissionTo('Update Policies');
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('Update Policies');
        $policy = Policy::factory()->create(['owner_id' => $owner->id, 'body' => 'Approved baseline']);
        $manager = app(PolicyRevisionManager::class);
        $revision = $manager->submit($policy, $owner, [
            'change_summary' => 'Capture the baseline.',
            'proposed_effective_date' => now()->subDay()->toDateString(),
        ]);

        try {
            $manager->review($revision, $owner, ['decision' => 'approved', 'review_summary' => 'Self review']);
            $this->fail('A submitter reviewed their own revision.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('policy_revision_reviews', ['policy_revision_id' => $revision->id]);
        }
        $policy->update(['body' => 'Changed after submission']);
        try {
            $manager->review($revision, $reviewer, ['decision' => 'approved', 'review_summary' => 'Stale']);
            $this->fail('A stale revision was approved.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('policy_revision_reviews', ['policy_revision_id' => $revision->id]);
        }
        $manager->review($revision, $reviewer, ['decision' => 'rejected', 'review_summary' => 'Superseded by current policy state.']);
        $replacement = $manager->submit($policy, $owner, [
            'change_summary' => 'Capture the changed baseline.',
            'proposed_effective_date' => now()->toDateString(),
        ]);
        $this->assertSame(2, $replacement->version);
        $this->assertDatabaseHas('policy_revisions', ['id' => $revision->id, 'status' => 'rejected']);
    }

    public function test_rejected_revision_is_immutable_and_a_later_approved_revision_drives_drift_state(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo(['Update Policies', 'Read Policies']);
        $policy = Policy::factory()->create(['owner_id' => $owner->id, 'body' => 'Controlled body']);
        $manager = app(PolicyRevisionManager::class);
        $first = $manager->submit($policy, $owner, ['change_summary' => 'First', 'proposed_effective_date' => now()->toDateString()]);
        $manager->review($first, $reviewer, ['decision' => 'rejected', 'review_summary' => 'Needs correction']);
        $second = $manager->submit($policy, $owner, ['change_summary' => 'Second', 'proposed_effective_date' => now()->toDateString()]);
        $manager->review($second, $reviewer, ['decision' => 'approved', 'review_summary' => 'Approved']);

        $this->assertSame(2, $second->version);
        $this->assertSame('current', $policy->fresh()->revision_governance_status);
        Sanctum::actingAs($reviewer);
        $this->getJson("/api/policies/{$policy->id}")->assertOk()
            ->assertJsonPath('data.revision_governance_status', 'current');
        $policy->update(['body' => 'Unapproved drift']);
        $this->assertSame('revision_required', $policy->fresh()->revision_governance_status);
        $this->getJson("/api/policies/{$policy->id}")->assertOk()
            ->assertJsonPath('data.revision_governance_status', 'revision_required');

        Sanctum::actingAs($owner);
        $this->getJson("/api/policies/{$policy->id}/revisions?per_page=1")
            ->assertOk()->assertJsonPath('per_page', 1)->assertJsonCount(1, 'data');

        $this->actingAs($reviewer, 'web');
        Livewire::test(RevisionsRelationManager::class, ['ownerRecord' => $policy, 'pageClass' => ViewPolicy::class])
            ->assertCanSeeTableRecords([$first, $second])->assertTableActionVisible('inspect', $second)
            ->assertSee('Current policy governance state: Revision Required');
        $this->view('filament.policy-revision', ['revision' => $second->fresh()->load(['submitter', 'review.reviewer'])])
            ->assertSee('Controlled body')->assertSee($second->fingerprint)->assertSee('Approved');
        $columns = collect(PolicyRevisionExporter::getColumns())->map->getName();
        $this->assertContains('policy_snapshot', $columns);
        $this->assertContains('review.fingerprint', $columns);

        $this->expectException(\LogicException::class);
        PolicyRevision::query()->findOrFail($first->id)->update(['change_summary' => 'Rewritten']);
    }

    public function test_server_fields_permissions_pending_bound_factories_and_retained_migration_are_governed(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $policy = Policy::factory()->create(['owner_id' => $owner->id]);
        Sanctum::actingAs($owner);
        $payload = ['change_summary' => 'Govern baseline.', 'proposed_effective_date' => now()->toDateString()];
        $this->postJson("/api/policies/{$policy->id}/revisions", $payload + ['version' => 99])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $revisionId = $this->postJson("/api/policies/{$policy->id}/revisions", $payload)
            ->assertCreated()->json('data.id');
        $this->postJson("/api/policies/{$policy->id}/revisions", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('policy');
        Sanctum::actingAs($outsider);
        $this->getJson("/api/policies/{$policy->id}/revisions")->assertForbidden();
        try {
            app(PolicyRevisionManager::class)->submit($policy, $outsider, $payload);
            $this->fail('Direct service submission bypassed authorization.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('policy_revisions', 1);

        $factoryRevision = PolicyRevision::factory()->create();
        $factoryPayload = [
            'policy_id' => $factoryRevision->policy_id, 'version' => $factoryRevision->version,
            'change_summary' => $factoryRevision->change_summary,
            'proposed_effective_date' => $factoryRevision->proposed_effective_date->toDateString(),
            'policy_snapshot' => $factoryRevision->policy_snapshot,
        ];
        $this->assertSame(hash('sha256', json_encode($factoryPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $factoryRevision->fingerprint);
        $factoryReview = PolicyRevisionReview::factory()->create();
        $reviewPayload = [
            'policy_revision_id' => $factoryReview->policy_revision_id, 'decision' => $factoryReview->decision->value,
            'review_summary' => $factoryReview->review_summary, 'revision_snapshot' => $factoryReview->revision_snapshot,
            'reviewed_by' => $factoryReview->reviewed_by, 'reviewed_at' => $factoryReview->reviewed_at->toISOString(),
        ];
        $this->assertSame(hash('sha256', json_encode($reviewPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $factoryReview->fingerprint);
        $this->assertSame('approved', $factoryReview->revision->fresh()->status->value);

        $migration = require database_path('migrations/2026_08_24_460000_create_policy_revision_lifecycle.php');
        $migration->down();
        $this->assertDatabaseHas('policy_revisions', ['id' => $revisionId]);
        $this->assertDatabaseHas('policy_revision_reviews', ['id' => $factoryReview->id]);
    }
}
