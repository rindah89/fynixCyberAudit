<?php

namespace Tests\Feature;

use App\Enums\RiskDomain;
use App\Enums\ThirdPartyEngagementStatus;
use App\Enums\ThirdPartyRiskDecisionType;
use App\Enums\VendorStatus;
use App\Filament\Resources\ThirdPartyRiskResource\Pages\ViewThirdPartyRisk;
use App\Filament\Resources\ThirdPartyRiskResource\RelationManagers\EngagementsRelationManager;
use App\Models\Risk;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementEvent;
use App\Models\User;
use App\Models\Vendor;
use App\ThirdPartyRisk\ThirdPartyEngagementManager;
use App\ThirdPartyRisk\ThirdPartyRiskManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class ThirdPartyEngagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_governed_engagement_moves_from_proposal_through_renewal_and_exit(): void
    {
        [$vendor, $proposer, $assessor, $decider, $approver, $owner] = $this->approvedContext();
        Sanctum::actingAs($proposer);
        $payload = [
            'code' => 'ENG-CLOUD-01', 'name' => 'Hosted resilience platform',
            'service_description' => 'Material hosted service supporting continuity operations.',
            'business_owner_id' => $owner->id, 'criticality' => 'critical', 'data_access' => true,
            'term_start_at' => today()->toDateString(), 'term_end_at' => today()->addYear()->toDateString(),
            'next_review_at' => today()->addMonths(6)->toDateString(),
        ];
        $id = $this->postJson("/api/vendors/{$vendor->id}/engagements", $payload + ['status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $id = $this->postJson("/api/vendors/{$vendor->id}/engagements", $payload)
            ->assertCreated()->assertJsonPath('data.status', 'proposed')->assertJsonPath('data.events.0.version', 1)->json('data.id');
        $engagement = ThirdPartyEngagement::query()->findOrFail($id);

        $this->postJson("/api/third-party-engagements/{$id}/events", ['status' => 'due_diligence', 'summary' => 'Due diligence inputs completed.'])->assertOk();
        $this->postJson("/api/third-party-engagements/{$id}/events", ['status' => 'approved', 'summary' => 'Self approval attempt.'])->assertForbidden();

        Sanctum::actingAs($approver);
        $this->postJson("/api/third-party-engagements/{$id}/events", ['status' => 'approved', 'summary' => 'Independent engagement approval.'])
            ->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.engagement_snapshot.approval_snapshot.assessment.assessor_id', $assessor->id)
            ->assertJsonPath('data.engagement_snapshot.approval_snapshot.decision.decided_by', $decider->id);
        $this->postJson("/api/third-party-engagements/{$id}/events", ['status' => 'active', 'summary' => 'Term activated after current approval check.'])
            ->assertOk()->assertJsonPath('data.to_status', 'active');
        $this->postJson("/api/third-party-engagements/{$id}/events", ['status' => 'renewal_review', 'summary' => 'Engagement entered renewal review.'])->assertOk();

        $riskManager = app(ThirdPartyRiskManager::class);
        $riskManager->assess($vendor, $assessor, $this->assessmentPayload());
        $riskManager->decide($vendor, $decider, ThirdPartyRiskDecisionType::Approved, [
            'rationale' => 'Renewal risk accepted.', 'conditions' => 'Continue annual assurance.',
            'expires_at' => today()->addYear(), 'next_review_at' => today()->addMonths(6),
        ]);
        $this->postJson("/api/third-party-engagements/{$id}/events", [
            'status' => 'active', 'summary' => 'Independent renewal approved against current risk evidence.',
            'renewed_term_end_at' => today()->addYears(2)->toDateString(), 'renewed_next_review_at' => today()->addYear()->toDateString(),
        ])->assertOk()->assertJsonPath('data.version', 6);
        $this->postJson("/api/third-party-engagements/{$id}/events", [
            'status' => 'exited', 'summary' => 'Engagement exit decision recorded.', 'exit_summary' => 'Service was transitioned to a replacement provider.',
            'data_disposition_statement' => 'Provider attested that organizational data was returned or deleted; execution is not independently verified.',
        ])->assertOk()->assertJsonPath('data.to_status', 'exited');

        $event = ThirdPartyEngagementEvent::query()->latest('id')->firstOrFail();
        $fingerprintPayload = $event->only(['third_party_engagement_id', 'version', 'from_status', 'to_status', 'summary', 'engagement_snapshot', 'recorded_by']);
        $fingerprintPayload['from_status'] = $event->from_status?->value;
        $fingerprintPayload['to_status'] = $event->to_status->value;
        $fingerprintPayload['recorded_at'] = $event->recorded_at->toIso8601String();
        $this->assertSame($event->fingerprint, hash('sha256', json_encode($fingerprintPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        $this->assertCount(7, $engagement->refresh()->events);
        $this->assertSame(ThirdPartyEngagementStatus::Exited, $engagement->status);
    }

    public function test_history_is_scoped_immutable_and_available_in_the_operator_workspace(): void
    {
        [$vendor, $manager, , , , $owner] = $this->approvedContext();
        $engagement = app(ThirdPartyEngagementManager::class)->propose($manager, $vendor, [
            'code' => 'ENG-SCOPE-01', 'name' => 'Scoped service', 'service_description' => 'Scoped engagement evidence.',
            'business_owner_id' => $owner->id, 'criticality' => 'high', 'data_access' => false,
            'term_start_at' => today(), 'term_end_at' => today()->addYear(), 'next_review_at' => today()->addMonths(6),
        ]);
        $reader = User::factory()->create();
        $reader->givePermissionTo('Read Vendors');
        Sanctum::actingAs($reader);
        $this->getJson("/api/vendors/{$vendor->id}/engagements?per_page=100")->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", [])->assertForbidden();
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->getJson("/api/third-party-engagements/{$engagement->id}")->assertForbidden();

        $this->actingAs($reader, 'web');
        Livewire::test(EngagementsRelationManager::class, ['ownerRecord' => $vendor, 'pageClass' => ViewThirdPartyRisk::class])
            ->assertCanSeeTableRecords([$engagement])->assertTableActionVisible('inspect', $engagement)->assertTableActionHidden('transition', $engagement);

        $event = $engagement->events()->firstOrFail();
        $this->expectException(\LogicException::class);
        $event->update(['summary' => 'Rewritten evidence']);
    }

    public function test_factories_and_retained_migration_preserve_reconstructible_evidence(): void
    {
        $event = ThirdPartyEngagementEvent::factory()->create();
        $event->refresh()->load('engagement');
        $this->assertSame($event->engagement->proposed_by, $event->recorded_by);
        $payload = $event->only(['third_party_engagement_id', 'version', 'from_status', 'to_status', 'summary', 'engagement_snapshot', 'recorded_by']);
        $payload['from_status'] = $event->from_status?->value;
        $payload['to_status'] = $event->to_status->value;
        $payload['recorded_at'] = $event->recorded_at->toIso8601String();
        $this->assertSame($event->fingerprint, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

        $migration = include database_path('migrations/2026_08_24_750000_create_third_party_engagement_history.php');
        $migration->down();
        $this->assertDatabaseHas('third_party_engagement_events', ['id' => $event->id, 'fingerprint' => $event->fingerprint]);
    }

    private function approvedContext(): array
    {
        $actors = collect(range(1, 5))->map(fn () => tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors'])));
        [$proposer, $assessor, $decider, $approver] = $actors->take(4)->all();
        $owner = User::factory()->create();
        $vendor = Vendor::factory()->create(['vendor_manager_id' => $proposer->id, 'status' => VendorStatus::ACCEPTED]);
        $riskManager = app(ThirdPartyRiskManager::class);
        $riskManager->assess($vendor, $assessor, $this->assessmentPayload());
        $riskManager->mapRisk($vendor, Risk::factory()->create(['domain' => RiskDomain::ThirdParty]));
        $riskManager->decide($vendor, $decider, ThirdPartyRiskDecisionType::Approved, [
            'rationale' => 'Residual exposure accepted.', 'conditions' => 'Annual reassessment.',
            'expires_at' => today()->addYear(), 'next_review_at' => today()->addMonths(6),
        ]);

        return [$vendor, $proposer, $assessor, $decider, $approver, $owner];
    }

    private function assessmentPayload(): array
    {
        return ['likelihood' => 4, 'impact' => 5, 'residual_likelihood' => 2, 'residual_impact' => 3,
            'risk_categories' => ['cybersecurity', 'privacy', 'operational'], 'assessment_summary' => 'Material hosted service exposure.',
            'treatment_summary' => 'Contractual controls, assurance review, notification, and exit planning.'];
    }
}
