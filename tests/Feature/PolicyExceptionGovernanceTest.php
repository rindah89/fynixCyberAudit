<?php

namespace Tests\Feature;

use App\Filament\Exports\PolicyExceptionExporter;
use App\Filament\Resources\PolicyResource\Pages\ViewPolicy;
use App\Filament\Resources\PolicyResource\RelationManagers\ExceptionsRelationManager;
use App\Models\Policy;
use App\Models\PolicyException;
use App\Models\PolicyExceptionDecision;
use App\Models\Risk;
use App\Models\User;
use App\PolicyCompliance\PolicyExceptionGovernanceManager;
use App\PolicyCompliance\PolicyRevisionContextManager;
use App\PolicyCompliance\PolicyRevisionManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PolicyExceptionGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_requester_submits_governed_exception_and_independent_editor_approves_it(): void
    {
        $requester = User::factory()->create();
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('Update Policies');
        $policy = Policy::factory()->create(['owner_id' => $requester->id]);
        $revision = app(PolicyRevisionManager::class)->submit($policy, $requester, [
            'change_summary' => 'Approve the policy context before requesting an exception.',
            'proposed_effective_date' => now()->toDateString(),
        ]);
        app(PolicyRevisionManager::class)->review($revision, $reviewer, [
            'decision' => 'approved', 'review_summary' => 'Policy context approved.',
        ]);

        Sanctum::actingAs($requester);
        $exception = $this->postJson("/api/policies/{$policy->id}/exception-requests", [
            'name' => 'Temporary privileged-access review interval',
            'description' => 'Permit a monthly review while the quarterly automation is replaced.',
            'justification' => 'The legacy connector is being decommissioned.',
            'risk_assessment' => 'Delayed review may extend elevated access exposure.',
            'compensating_controls' => 'Weekly manual privileged-account reconciliation.',
            'effective_date' => now()->addDay()->toDateString(),
            'expiration_date' => now()->addMonths(3)->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.requested_by', $requester->id)
            ->assertJsonPath('data.governance_snapshot.policy.id', $policy->id)
            ->assertJsonPath('data.governance_snapshot.approved_revision.fingerprint', $revision->fingerprint)
            ->json('data');

        Sanctum::actingAs($reviewer);
        $this->postJson("/api/policy-exceptions/{$exception['id']}/decisions", [
            'decision' => 'approved', 'decision_summary' => 'Caller-owned fields are forbidden.', 'decided_by' => $requester->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('decided_by');
        $decision = $this->postJson("/api/policy-exceptions/{$exception['id']}/decisions", [
            'decision' => 'approved',
            'decision_summary' => 'The bounded interval and compensating control are acceptable.',
        ])->assertCreated()
            ->assertJsonPath('data.decided_by', $reviewer->id)
            ->assertJsonPath('data.exception.status', 'approved')
            ->json('data');

        $this->assertNotSame($requester->id, $decision['decided_by']);
        $this->assertDatabaseHas('policy_exception_decisions', [
            'policy_exception_id' => $exception['id'], 'decision' => 'approved',
        ]);
    }

    public function test_server_fields_authorization_independence_and_stale_context_are_enforced(): void
    {
        $requester = User::factory()->create();
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('Update Policies');
        $outsider = User::factory()->create();
        $policy = Policy::factory()->create(['owner_id' => $requester->id, 'name' => 'Approved policy context']);
        $payload = $this->requestPayload();
        Sanctum::actingAs($requester);
        $this->postJson("/api/policies/{$policy->id}/exception-requests", $payload + ['status' => 'approved'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $id = $this->postJson("/api/policies/{$policy->id}/exception-requests", $payload)->assertCreated()->json('data.id');

        $exception = PolicyException::query()->findOrFail($id);
        try {
            app(PolicyExceptionGovernanceManager::class)->decide($exception, $requester, ['decision' => 'approved', 'decision_summary' => 'Self approval']);
            $this->fail('The requester approved their own exception.');
        } catch (HttpException|ValidationException $error) {
            $this->assertDatabaseCount('policy_exception_decisions', 0);
        }
        Sanctum::actingAs($outsider);
        $this->getJson("/api/policies/{$policy->id}/exception-requests")->assertForbidden();
        try {
            app(PolicyExceptionGovernanceManager::class)->submit($policy, $outsider, $payload);
            $this->fail('Direct submission bypassed authorization.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }

        $policy->update(['body' => 'Changed material policy body']);
        try {
            app(PolicyExceptionGovernanceManager::class)->decide($exception, $reviewer, ['decision' => 'approved', 'decision_summary' => 'Stale approval']);
            $this->fail('A stale exception request was approved.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('policy_exception_decisions', 0);
        }
        app(PolicyExceptionGovernanceManager::class)->decide($exception, $reviewer, ['decision' => 'denied', 'decision_summary' => 'Policy context changed.']);
        $this->assertDatabaseHas('policy_exceptions', ['id' => $id, 'status' => 'denied']);

        $mappingPolicy = Policy::factory()->create(['owner_id' => $requester->id]);
        $mappingException = app(PolicyExceptionGovernanceManager::class)->submit($mappingPolicy, $requester, $payload);
        app(PolicyRevisionContextManager::class)->attachRisk($mappingPolicy, Risk::factory()->create());
        try {
            app(PolicyExceptionGovernanceManager::class)->decide($mappingException, $reviewer, ['decision' => 'approved', 'decision_summary' => 'Changed mapping']);
            $this->fail('An exception with changed governed mappings was approved.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('policy_exception_decisions', ['policy_exception_id' => $mappingException->id]);
        }

        $deletedPolicy = Policy::factory()->create(['owner_id' => $requester->id]);
        $deletedException = app(PolicyExceptionGovernanceManager::class)->submit($deletedPolicy, $requester, $payload);
        $deletedPolicy->delete();
        try {
            app(PolicyExceptionGovernanceManager::class)->decide($deletedException, $reviewer, ['decision' => 'approved', 'decision_summary' => 'Deleted policy']);
            $this->fail('An exception for a deleted policy was approved.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('policy_exception_decisions', ['policy_exception_id' => $deletedException->id]);
        }
        $legacy = PolicyException::factory()->pending()->create(['policy_id' => $policy->id]);
        try {
            app(PolicyExceptionGovernanceManager::class)->decide($legacy, $reviewer, ['decision' => 'approved', 'decision_summary' => 'Legacy']);
            $this->fail('Legacy evidence entered the governed lifecycle.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('policy_exception_decisions', ['policy_exception_id' => $legacy->id]);
        }
    }

    public function test_approval_can_be_revoked_while_request_and_decisions_remain_immutable(): void
    {
        $requester = User::factory()->create();
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('Update Policies');
        $policy = Policy::factory()->create(['owner_id' => $requester->id]);
        $manager = app(PolicyExceptionGovernanceManager::class);
        $exception = $manager->submit($policy, $requester, $this->requestPayload());
        $first = $manager->decide($exception, $reviewer, ['decision' => 'approved', 'decision_summary' => 'Bounded approval.']);
        $second = $manager->decide($exception->fresh(), $reviewer, ['decision' => 'revoked', 'decision_summary' => 'Compensating control is no longer available.']);

        $this->assertSame(2, $second->version);
        $this->assertFalse($exception->fresh()->isActive());
        $this->assertDatabaseCount('policy_exception_decisions', 2);
        try {
            $exception->fresh()->update(['justification' => 'Rewritten']);
            $this->fail('Governed exception evidence was mutable.');
        } catch (\LogicException) {
            $this->assertNotSame('Rewritten', $exception->fresh()->justification);
        }
        $this->expectException(\LogicException::class);
        $first->update(['decision_summary' => 'Rewritten']);
    }

    public function test_operator_history_export_factories_and_migration_preserve_governed_evidence(): void
    {
        $requester = User::factory()->create();
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo(['Update Policies', 'Read Policies']);
        $policy = Policy::factory()->create(['owner_id' => $requester->id]);
        $manager = app(PolicyExceptionGovernanceManager::class);
        $exception = $manager->submit($policy, $requester, $this->requestPayload());
        $decision = $manager->decide($exception, $reviewer, ['decision' => 'approved', 'decision_summary' => 'Governed approval.']);

        Sanctum::actingAs($requester);
        $this->getJson("/api/policies/{$policy->id}/exception-requests?per_page=1")
            ->assertOk()->assertJsonPath('per_page', 1)->assertJsonCount(1, 'data');
        $this->actingAs($reviewer, 'web');
        Livewire::test(ExceptionsRelationManager::class, ['ownerRecord' => $policy, 'pageClass' => ViewPolicy::class])
            ->assertCanSeeTableRecords([$exception])->assertTableActionVisible('inspect', $exception);
        $this->view('filament.policy-exception-governance', ['exception' => $exception->fresh()->load(['requester', 'decisions.decider'])])
            ->assertSee('Governed approval.')->assertSee($exception->governance_fingerprint)->assertSee($decision->fingerprint);
        $columns = collect(PolicyExceptionExporter::getColumns())->map->getName();
        $this->assertContains('governance_snapshot', $columns);
        $this->assertContains('decision_history', $columns);

        $factoryException = PolicyException::factory()->governed()->create();
        $factoryDecision = PolicyExceptionDecision::factory()->create();
        $exceptionPayload = [
            'policy_id' => $factoryException->policy_id, 'requested_by' => $factoryException->requested_by,
            'requested_at' => $factoryException->submitted_at->toISOString(), 'governance_snapshot' => $factoryException->governance_snapshot,
        ];
        $this->assertSame(hash('sha256', json_encode($exceptionPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $factoryException->governance_fingerprint);
        $decisionPayload = [
            'policy_exception_id' => $factoryDecision->policy_exception_id, 'version' => $factoryDecision->version,
            'decision' => $factoryDecision->decision->value, 'decision_summary' => $factoryDecision->decision_summary,
            'exception_snapshot' => $factoryDecision->exception_snapshot, 'decided_by' => $factoryDecision->decided_by,
            'decided_at' => $factoryDecision->decided_at->toISOString(),
        ];
        $this->assertSame(hash('sha256', json_encode($decisionPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $factoryDecision->fingerprint);
        $this->assertSame('approved', $factoryDecision->exception->fresh()->status->value);
        $migration = require database_path('migrations/2026_08_24_470000_create_governed_policy_exception_lifecycle.php');
        $migration->down();
        $this->assertDatabaseHas('policy_exceptions', ['id' => $exception->id]);
        $this->assertDatabaseHas('policy_exception_decisions', ['id' => $decision->id]);
    }

    private function requestPayload(): array
    {
        return [
            'name' => 'Temporary policy exception', 'description' => 'A bounded exception request.',
            'justification' => 'A documented business dependency exists.',
            'risk_assessment' => 'The deviation increases access risk.',
            'compensating_controls' => 'Weekly manual reconciliation.',
            'effective_date' => now()->addDay()->toDateString(), 'expiration_date' => now()->addMonths(3)->toDateString(),
        ];
    }
}
