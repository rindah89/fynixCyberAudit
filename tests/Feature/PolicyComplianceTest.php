<?php

namespace Tests\Feature;

use App\Enums\PolicyAttestationOutcome;
use App\Filament\Resources\PolicyObligationResource;
use App\Models\Policy;
use App\Models\PolicyException;
use App\Models\PolicyObligation;
use App\Models\User;
use App\PolicyCompliance\PolicyCompliance;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PolicyComplianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_owner_can_create_and_attest_a_recurring_obligation(): void
    {
        Carbon::setTestNow('2026-08-23 12:00:00');
        $this->seed(RolePermissionSeeder::class);

        $owner = User::factory()->create();
        $owner->givePermissionTo('Update Policies');
        $policy = Policy::factory()->create(['owner_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $obligationId = $this->postJson("/api/policies/{$policy->id}/obligations", [
            'code' => 'POL-ACCESS-01',
            'title' => 'Quarterly access review',
            'description' => 'Review privileged access and retain evidence.',
            'owner_id' => $owner->id,
            'frequency' => 'quarterly',
            'next_due_at' => '2026-08-31',
        ])->assertCreated()
            ->assertJsonPath('data.compliance_status', 'due')
            ->json('data.id');

        $this->postJson("/api/policy-obligations/{$obligationId}/attest", [
            'outcome' => PolicyAttestationOutcome::Compliant->value,
            'statement' => 'All privileged accounts were reviewed.',
            'evidence_reference' => 'EVIDENCE-2026-Q3-ACCESS',
        ])->assertCreated()
            ->assertJsonPath('data.outcome', PolicyAttestationOutcome::Compliant->value)
            ->assertJsonPath('obligation.compliance_status', 'compliant')
            ->assertJsonPath('obligation.next_due_at', '2026-11-23T12:00:00.000000Z');

        $this->assertDatabaseHas('policy_attestations', [
            'policy_obligation_id' => $obligationId,
            'attested_by' => $owner->id,
            'outcome' => PolicyAttestationOutcome::Compliant->value,
            'evidence_reference' => 'EVIDENCE-2026-Q3-ACCESS',
        ]);
    }

    public function test_only_accountable_owner_or_policy_manager_can_attest(): void
    {
        $policyOwner = User::factory()->create();
        $obligationOwner = User::factory()->create();
        $stranger = User::factory()->create();
        $policy = Policy::factory()->create(['owner_id' => $policyOwner->id]);
        $obligation = PolicyObligation::create([
            'policy_id' => $policy->id,
            'owner_id' => $obligationOwner->id,
            'code' => 'POL-OWNER-01',
            'title' => 'Owner attestation',
            'frequency' => 'annual',
            'next_due_at' => now()->addMonth(),
        ]);

        Sanctum::actingAs($stranger);
        $this->postJson("/api/policy-obligations/{$obligation->id}/attest", [
            'outcome' => 'compliant',
            'statement' => 'I should not be allowed to attest.',
        ])->assertForbidden();

        Sanctum::actingAs($obligationOwner);
        $this->postJson("/api/policy-obligations/{$obligation->id}/attest", [
            'outcome' => 'compliant',
            'statement' => 'I own and verified this obligation.',
        ])->assertCreated();

        $this->assertDatabaseCount('policy_attestations', 1);
    }

    public function test_overdue_status_and_re_attestation_preserve_the_history(): void
    {
        Carbon::setTestNow('2026-01-15 09:00:00');
        $owner = User::factory()->create();
        $owner->givePermissionTo(['Read Policies', 'Update Policies']);
        $policy = Policy::factory()->create(['owner_id' => $owner->id]);
        $obligation = PolicyObligation::create([
            'policy_id' => $policy->id,
            'owner_id' => $owner->id,
            'code' => 'POL-HISTORY-01',
            'title' => 'Retain attestation history',
            'frequency' => 'monthly',
            'next_due_at' => now(),
        ]);
        Sanctum::actingAs($owner);

        $this->postJson("/api/policy-obligations/{$obligation->id}/attest", [
            'outcome' => 'compliant',
            'statement' => 'January review completed.',
        ])->assertCreated();

        Carbon::setTestNow('2026-02-16 09:00:00');
        $this->getJson("/api/policies/{$policy->id}")
            ->assertOk()
            ->assertJsonPath('data.obligations.0.compliance_status', 'overdue');

        $this->postJson("/api/policy-obligations/{$obligation->id}/attest", [
            'outcome' => 'non_compliant',
            'statement' => 'February review found an unresolved gap.',
        ])->assertCreated()
            ->assertJsonPath('obligation.compliance_status', 'non_compliant');

        $this->assertDatabaseCount('policy_attestations', 2);
        $this->assertSame(
            ['compliant', 'non_compliant'],
            $obligation->attestations()->oldest('attested_at')->get()->pluck('outcome')->map->value->all(),
        );
    }

    public function test_soft_deleted_obligation_retains_immutable_attestation_history(): void
    {
        $owner = User::factory()->create();
        $policy = Policy::factory()->create(['owner_id' => $owner->id]);
        $obligation = PolicyObligation::create([
            'policy_id' => $policy->id,
            'owner_id' => $owner->id,
            'code' => 'POL-RETAIN-01',
            'title' => 'Retain compliance evidence',
            'frequency' => 'one_time',
            'next_due_at' => now(),
        ]);
        $attestation = app(PolicyCompliance::class)->attest(
            $obligation,
            $owner,
            PolicyAttestationOutcome::Compliant,
            'Evidence reviewed and retained.',
        );

        $obligation->delete();

        $this->assertSoftDeleted('policy_obligations', ['id' => $obligation->id]);
        $this->assertDatabaseHas('policy_attestations', ['id' => $attestation->id]);

        $this->expectException(\LogicException::class);
        $attestation->update(['statement' => 'Attempted rewrite.']);
    }

    public function test_authorized_operator_can_open_the_obligation_workspace(): void
    {
        $operator = User::factory()->create();
        $policy = Policy::factory()->create(['owner_id' => $operator->id]);
        $obligation = PolicyObligation::create([
            'policy_id' => $policy->id,
            'owner_id' => $operator->id,
            'code' => 'POL-WORKSPACE-01',
            'title' => 'Workspace smoke test',
            'frequency' => 'annual',
            'next_due_at' => now()->addYear(),
        ]);

        $this->actingAs($operator)
            ->get(PolicyObligationResource::getUrl('index'))
            ->assertOk();

        $this->get(PolicyObligationResource::getUrl('view', ['record' => $obligation]))
            ->assertOk();
    }

    public function test_pending_exception_cannot_qualify_an_attestation(): void
    {
        $owner = User::factory()->create();
        $policy = Policy::factory()->create(['owner_id' => $owner->id]);
        $obligation = PolicyObligation::create([
            'policy_id' => $policy->id,
            'owner_id' => $owner->id,
            'code' => 'POL-EXCEPTION-01',
            'title' => 'Exception validation',
            'frequency' => 'annual',
            'next_due_at' => now()->addYear(),
        ]);
        $pendingException = PolicyException::factory()->pending()->create(['policy_id' => $policy->id]);
        Sanctum::actingAs($owner);

        $this->postJson("/api/policy-obligations/{$obligation->id}/attest", [
            'outcome' => 'non_compliant',
            'statement' => 'Attempt to use an unapproved exception.',
            'policy_exception_id' => $pendingException->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('policy_exception_id');

        $this->assertDatabaseCount('policy_attestations', 0);
    }

    public function test_only_active_exception_from_the_same_policy_can_be_linked(): void
    {
        $owner = User::factory()->create();
        $policy = Policy::factory()->create(['owner_id' => $owner->id]);
        $otherPolicy = Policy::factory()->create();
        $obligation = PolicyObligation::create([
            'policy_id' => $policy->id,
            'owner_id' => $owner->id,
            'code' => 'POL-EXCEPTION-02',
            'title' => 'Scoped exception validation',
            'frequency' => 'annual',
            'next_due_at' => now()->addYear(),
        ]);
        $samePolicyException = PolicyException::factory()->approved()->create([
            'policy_id' => $policy->id,
            'effective_date' => now()->subDay(),
            'expiration_date' => today(),
        ]);
        $otherPolicyException = PolicyException::factory()->approved()->create([
            'policy_id' => $otherPolicy->id,
            'effective_date' => now()->subDay(),
            'expiration_date' => now()->addMonth(),
        ]);
        Sanctum::actingAs($owner);

        $this->assertTrue(PolicyException::active()->whereKey($samePolicyException->id)->exists());

        $this->postJson("/api/policy-obligations/{$obligation->id}/attest", [
            'outcome' => 'non_compliant',
            'statement' => 'Gap covered by an approved exception.',
            'policy_exception_id' => $samePolicyException->id,
        ])->assertCreated();

        $this->postJson("/api/policy-obligations/{$obligation->id}/attest", [
            'outcome' => 'non_compliant',
            'statement' => 'Attempt to link another policy exception.',
            'policy_exception_id' => $otherPolicyException->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('policy_exception_id');

        $this->assertDatabaseCount('policy_attestations', 1);
    }

    public function test_inactive_obligation_cannot_be_attested(): void
    {
        $owner = User::factory()->create();
        $policy = Policy::factory()->create(['owner_id' => $owner->id]);
        $obligation = PolicyObligation::create([
            'policy_id' => $policy->id,
            'owner_id' => $owner->id,
            'code' => 'POL-INACTIVE-01',
            'title' => 'Inactive obligation',
            'frequency' => 'annual',
            'next_due_at' => now()->addYear(),
            'is_active' => false,
        ]);
        Sanctum::actingAs($owner);

        $this->postJson("/api/policy-obligations/{$obligation->id}/attest", [
            'outcome' => 'compliant',
            'statement' => 'This obligation is not active.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('policy_obligation_id');

        $this->assertDatabaseCount('policy_attestations', 0);
    }
}
