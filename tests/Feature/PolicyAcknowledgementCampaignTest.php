<?php

namespace Tests\Feature;

use App\Filament\Exports\PolicyAcknowledgementAssignmentExporter;
use App\Filament\Resources\PolicyAcknowledgementCampaignResource;
use App\Filament\Resources\PolicyAcknowledgementCampaignResource\Pages\ViewPolicyAcknowledgementCampaign;
use App\Filament\Resources\PolicyAcknowledgementCampaignResource\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\PolicyAcknowledgementResource;
use App\Filament\Resources\PolicyAcknowledgementResource\Pages\ListPolicyAcknowledgements;
use App\Models\Policy;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyAcknowledgementCampaign;
use App\Models\User;
use App\PolicyCompliance\PolicyAcknowledgementManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PolicyAcknowledgementCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_policy_manager_launches_versioned_campaign_with_explicit_audience_and_snapshot(): void
    {
        $manager = $this->manager();
        $owner = User::factory()->create();
        $audience = User::factory()->count(2)->create();
        $policy = $this->policy($owner);
        Sanctum::actingAs($manager);
        $payload = [
            'title' => 'Annual acceptable-use acknowledgement',
            'instructions' => 'Read the assigned policy version before acknowledging.',
            'due_at' => now()->addWeek()->toIso8601String(),
            'audience_user_ids' => $audience->pluck('id')->all(),
        ];

        $this->postJson("/api/policies/{$policy->id}/acknowledgement-campaigns", $payload + ['version' => 99])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $campaignId = $this->postJson("/api/policies/{$policy->id}/acknowledgement-campaigns", $payload)
            ->assertCreated()->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.policy_snapshot.code', $policy->code)
            ->assertJsonCount(2, 'data.assignments')->json('data.id');
        $this->assertDatabaseHas('policy_acknowledgement_campaigns', ['id' => $campaignId, 'launched_by' => $manager->id]);

        $this->postJson("/api/policies/{$policy->id}/acknowledgement-campaigns", $payload)
            ->assertCreated()->assertJsonPath('data.version', 2);
        $campaign = PolicyAcknowledgementCampaign::query()->findOrFail($campaignId);
        $this->assertSame(hash('sha256', json_encode($campaign->policy_snapshot, JSON_THROW_ON_ERROR)), $campaign->policy_fingerprint);
        $factoryCampaign = PolicyAcknowledgementCampaign::factory()->create();
        $this->assertSame($factoryCampaign->policy_id, $factoryCampaign->policy_snapshot['id']);
        $factoryAcknowledgement = PolicyAcknowledgement::factory()->create();
        $this->assertSame($factoryAcknowledgement->assignment->campaign->policy_fingerprint, $factoryAcknowledgement->policy_fingerprint);
        $this->assertSame($factoryAcknowledgement->assignment->campaign->id, $factoryAcknowledgement->campaign_snapshot['id']);

        foreach ([
            ['effective_date' => null, 'retired_date' => null],
            ['effective_date' => today()->addDay(), 'retired_date' => null],
            ['effective_date' => today()->subMonth(), 'retired_date' => today()->subDay()],
        ] as $dates) {
            $ineligible = Policy::factory()->create($dates + ['owner_id' => $manager->id, 'body' => '<p>Policy content.</p>']);
            $this->postJson("/api/policies/{$ineligible->id}/acknowledgement-campaigns", $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('policy');
        }
    }

    public function test_assigned_employee_sees_only_their_work_and_records_immutable_acknowledgement(): void
    {
        $manager = $this->manager();
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $policy = $this->policy($manager);
        $campaign = app(PolicyAcknowledgementManager::class)->launch($policy, $manager, [
            'title' => 'Security policy acknowledgement', 'due_at' => now()->addWeek(),
            'audience_user_ids' => [$employee->id, $other->id],
        ]);
        $assignment = $campaign->assignments()->where('user_id', $employee->id)->firstOrFail();
        $otherAssignment = $campaign->assignments()->where('user_id', $other->id)->firstOrFail();

        Sanctum::actingAs($employee);
        $this->getJson('/api/policy-acknowledgements/mine')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assignment->id);
        $this->postJson("/api/policy-acknowledgement-assignments/{$otherAssignment->id}/acknowledge", ['acknowledged' => true])->assertForbidden();
        $acknowledgementId = $this->postJson("/api/policy-acknowledgement-assignments/{$assignment->id}/acknowledge", [
            'acknowledged' => true, 'comment' => 'Read and understood.', 'client_reference' => 'HRIS-SESSION-44',
            'statement' => 'caller supplied',
        ])->assertUnprocessable()->assertJsonValidationErrors('statement');
        $acknowledgementId = $this->postJson("/api/policy-acknowledgement-assignments/{$assignment->id}/acknowledge", [
            'acknowledged' => true, 'comment' => 'Read and understood.', 'client_reference' => 'HRIS-SESSION-44',
        ])->assertCreated()->assertJsonPath('data.statement', PolicyAcknowledgementManager::STATEMENT)
            ->assertJsonPath('data.policy_fingerprint', $campaign->policy_fingerprint)->json('data.id');
        $this->postJson("/api/policy-acknowledgement-assignments/{$assignment->id}/acknowledge", ['acknowledged' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('acknowledgement');

        $policy->update(['body' => '<p>Materially changed after launch.</p>']);
        $acknowledgement = PolicyAcknowledgement::query()->findOrFail($acknowledgementId);
        $this->assertStringContainsString('Original policy body', $acknowledgement->policy_snapshot['body']);
        try {
            $acknowledgement->update(['comment' => 'Rewritten']);
            $this->fail('Acknowledgement evidence was mutable.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('policy_acknowledgements', ['id' => $acknowledgementId, 'comment' => 'Read and understood.']);
        }
        $this->actingAs($employee, 'web');
        $this->assertTrue(PolicyAcknowledgementResource::canView($assignment));
        $this->assertFalse(PolicyAcknowledgementResource::canView($otherAssignment));
        $this->assertSame([$assignment->id], PolicyAcknowledgementResource::getEloquentQuery()->pluck('id')->all());
        Livewire::test(ListPolicyAcknowledgements::class)->assertCanSeeTableRecords([$assignment])
            ->assertCanNotSeeTableRecords([$otherAssignment]);
    }

    public function test_campaign_reports_due_state_and_governed_closure(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');
        $manager = $this->manager();
        $employee = User::factory()->create();
        $policy = $this->policy($manager);
        $campaign = app(PolicyAcknowledgementManager::class)->launch($policy, $manager, [
            'title' => 'Time-bound campaign', 'due_at' => now()->addDay(), 'audience_user_ids' => [$employee->id],
        ]);
        $assignment = $campaign->assignments()->firstOrFail();
        Carbon::setTestNow('2026-08-26 12:00:00');
        $this->assertSame('overdue', $assignment->fresh()->acknowledgement_status);
        $this->assertSame('overdue', $campaign->fresh()->campaign_status);

        Sanctum::actingAs($manager);
        $this->getJson("/api/policy-acknowledgement-campaigns/{$campaign->id}/report")
            ->assertOk()->assertJsonPath('data.0.acknowledgement_status', 'overdue');
        $this->postJson("/api/policy-acknowledgement-campaigns/{$campaign->id}/close")
            ->assertOk()->assertJsonPath('data.campaign_status', 'closed');
        Sanctum::actingAs($employee);
        $this->postJson("/api/policy-acknowledgement-assignments/{$assignment->id}/acknowledge", ['acknowledged' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('campaign');

        $campaign = $campaign->fresh();
        try {
            $campaign->update(['title' => 'Rewritten']);
            $this->fail('Campaign evidence was mutable.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('policy_acknowledgement_campaigns', ['id' => $campaign->id, 'title' => 'Time-bound campaign']);
        }
        $manager->delete();
        $this->assertSame($manager->name, $campaign->fresh()->launcher->name);
        $this->assertSame($manager->name, $campaign->fresh()->closer->name);
    }

    public function test_policy_owner_can_manage_campaign_workspace_and_export_contract(): void
    {
        $owner = User::factory()->create();
        $employee = User::factory()->create();
        $policy = $this->policy($owner);
        $campaign = app(PolicyAcknowledgementManager::class)->launch($policy, $owner, [
            'title' => 'Owner campaign', 'due_at' => now()->addWeek(), 'audience_user_ids' => [$employee->id],
        ]);
        $this->actingAs($owner, 'web');
        $this->assertTrue(PolicyAcknowledgementCampaignResource::canViewAny());
        $this->assertTrue(PolicyAcknowledgementCampaignResource::canView($campaign));
        $this->assertSame([$campaign->id], PolicyAcknowledgementCampaignResource::getEloquentQuery()->pluck('id')->all());
        Livewire::test(AssignmentsRelationManager::class, [
            'ownerRecord' => $campaign, 'pageClass' => ViewPolicyAcknowledgementCampaign::class,
        ])->assertCanSeeTableRecords([$campaign->assignments()->firstOrFail()])
            ->assertTableActionVisible('inspect', $campaign->assignments()->firstOrFail());
        $rendered = view('filament.policy-acknowledgement', [
            'assignment' => $campaign->assignments()->with(['user', 'campaign', 'acknowledgement'])->firstOrFail(),
        ])->render();
        $this->assertStringContainsString('Original policy body and requirements.', $rendered);

        $columns = collect(PolicyAcknowledgementAssignmentExporter::getColumns())->map(fn ($column) => $column->getName());
        $this->assertContains('acknowledgement_status', $columns);
        $this->assertContains('campaign.policy_fingerprint', $columns);
        $this->assertContains('campaign.policy_snapshot', $columns);
        $this->assertContains('acknowledgement.acknowledged_at', $columns);
    }

    public function test_campaign_service_reauthorizes_current_policy_assignment_and_closure_state(): void
    {
        $manager = $this->manager();
        $employee = User::factory()->create();
        $outsider = User::factory()->create();
        $policy = $this->policy($manager);

        try {
            app(PolicyAcknowledgementManager::class)->launch($policy, $outsider, [
                'title' => 'Unauthorized campaign', 'due_at' => now()->addWeek(), 'audience_user_ids' => [$employee->id],
            ]);
            $this->fail('Direct campaign launch bypassed current policy authorization.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $campaign = app(PolicyAcknowledgementManager::class)->launch($policy, $manager, [
            'title' => 'Authorized campaign', 'due_at' => now()->addWeek(), 'audience_user_ids' => [$employee->id],
        ]);
        $assignment = $campaign->assignments()->firstOrFail();
        try {
            app(PolicyAcknowledgementManager::class)->acknowledge($assignment, $outsider, ['acknowledged' => true]);
            $this->fail('Direct acknowledgement bypassed the current assignment owner.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        try {
            app(PolicyAcknowledgementManager::class)->close($campaign, $outsider);
            $this->fail('Direct closure bypassed current policy authorization.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('policy_acknowledgements', 0);
        $this->assertNull($campaign->fresh()->closed_at);

        $policy->delete();
        $closed = app(PolicyAcknowledgementManager::class)->close($campaign, $manager);
        $this->assertNotNull($closed->closed_at);
        $this->assertSame($policy->id, $closed->policy->id);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('Update Policies');

        return $user;
    }

    private function policy(User $owner): Policy
    {
        return Policy::factory()->create([
            'owner_id' => $owner->id, 'effective_date' => today()->subDay(), 'retired_date' => null,
            'body' => '<p>Original policy body and requirements.</p>', 'document_path' => null,
        ]);
    }
}
