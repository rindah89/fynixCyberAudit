<?php

namespace Tests\Feature;

use App\Filament\Resources\ThirdPartyRiskResource\Pages\ViewThirdPartyRisk;
use App\Filament\Resources\ThirdPartyRiskResource\RelationManagers\EngagementsRelationManager;
use App\Models\ThirdPartyEngagementMonitoringIndicator;
use App\Models\ThirdPartyEngagementOffboardingCompletion;
use App\Models\ThirdPartyEngagementOffboardingReadinessReview;
use App\Models\ThirdPartyEngagementOffboardingRequirement;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class ThirdPartyEngagementOffboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_exit_requires_complete_independently_reviewed_current_offboarding_evidence(): void
    {
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $manager = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $owner = User::factory()->create();
        $reviewer = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $exitApprover = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));

        Sanctum::actingAs($manager);
        $requirementId = $this->postJson("/api/third-party-engagements/{$engagement->id}/offboarding-requirements", [
            'category' => 'access', 'title' => 'Revoke provider access',
            'acceptance_criteria' => 'All named provider identities and credentials are disabled.',
            'owner_id' => $owner->id, 'due_at' => today()->addMonth()->toDateString(), 'required' => true,
        ])->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');

        Sanctum::actingAs($exitApprover);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", [
            'status' => 'exited', 'summary' => 'Premature exit.', 'exit_summary' => 'Not ready.', 'data_disposition_statement' => 'Not complete.',
        ])->assertUnprocessable()->assertJsonValidationErrors('requirements');

        Sanctum::actingAs($owner);
        $this->postJson("/api/third-party-engagement-offboarding-requirements/{$requirementId}/complete", [
            'completion_summary' => 'Named provider identities were disabled and credentials rotated.', 'source_reference' => 'IAM-OFFBOARD-2043',
        ])->assertCreated()->assertJsonPath('data.version', 1);

        Sanctum::actingAs($reviewer);
        $reviewId = $this->postJson("/api/third-party-engagements/{$engagement->id}/offboarding-readiness-reviews", [
            'decision' => 'ready', 'summary' => 'All required offboarding controls have attributable completion evidence.',
        ])->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');

        Sanctum::actingAs($manager);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/offboarding-requirements", [
            'category' => 'knowledge', 'title' => 'Archive transition knowledge', 'acceptance_criteria' => 'Current transition records are archived.',
            'owner_id' => User::factory()->create()->id, 'due_at' => today()->addMonth()->toDateString(), 'required' => false,
        ])->assertCreated();
        Sanctum::actingAs($exitApprover);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", [
            'status' => 'exited', 'summary' => 'Stale readiness.', 'exit_summary' => 'Not current.', 'data_disposition_statement' => 'Not current.',
        ])->assertUnprocessable()->assertJsonValidationErrors('offboarding_readiness');
        Sanctum::actingAs($reviewer);
        $reviewId = $this->postJson("/api/third-party-engagements/{$engagement->id}/offboarding-readiness-reviews", [
            'decision' => 'ready', 'summary' => 'The updated exit-control scope is ready.',
        ])->assertCreated()->assertJsonPath('data.version', 2)->json('data.id');

        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", [
            'status' => 'exited', 'summary' => 'Reviewer self-approval.', 'exit_summary' => 'Service transitioned.', 'data_disposition_statement' => 'Provider reported disposition complete.',
        ])->assertForbidden();

        Sanctum::actingAs($exitApprover);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", [
            'status' => 'exited', 'summary' => 'Independent exit approved.', 'exit_summary' => 'Service transitioned to the approved replacement.',
            'data_disposition_statement' => 'Provider reported return or deletion of organizational data.',
        ])->assertOk()->assertJsonPath('data.engagement_snapshot.offboarding_readiness_snapshot.id', $reviewId);

        $this->view('filament.third-party-engagement', ['engagement' => $engagement->fresh(['businessOwner', 'events.actor', 'contractRiskReviews.reviewer', 'dueDiligenceReviews.reviewer', 'onboardingRequirements.owner', 'onboardingRequirements.definer', 'onboardingRequirements.completions.completer', 'onboardingReadinessReviews.reviewer', 'offboardingRequirements.owner', 'offboardingRequirements.definer', 'offboardingRequirements.completions.completer', 'offboardingReadinessReviews.reviewer', 'monitoringIndicators.latestObservations'])])
            ->assertSee('Governed offboarding controls')->assertSee('Revoke provider access')->assertSee('Independent offboarding-readiness history')->assertSee('IAM-OFFBOARD-2043');

        $reader = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Read Vendors'));
        Sanctum::actingAs($reader);
        $this->getJson("/api/third-party-engagements/{$engagement->id}/offboarding-requirements?per_page=100")
            ->assertOk()->assertJsonPath('data.1.id', $requirementId)->assertJsonCount(1, 'data.1.completions');
        $this->getJson("/api/third-party-engagements/{$engagement->id}/offboarding-readiness-reviews?per_page=100")
            ->assertOk()->assertJsonPath('data.0.id', $reviewId);
    }

    public function test_offboarding_factories_are_reconstructible_and_routine_rollback_retains_history(): void
    {
        $requirement = ThirdPartyEngagementOffboardingRequirement::factory()->create();
        $completion = ThirdPartyEngagementOffboardingCompletion::factory()->create();
        $review = ThirdPartyEngagementOffboardingReadinessReview::factory()->create();

        foreach ([$requirement, $completion, $review] as $evidence) {
            $payload = collect($evidence->getAttributes())->except(['id', 'fingerprint', 'created_at', 'updated_at'])
                ->map(function (mixed $value, string $key) use ($evidence): mixed {
                    return match ($evidence->getCasts()[$key] ?? null) {
                        'array' => $evidence->{$key}, 'boolean' => (bool) $evidence->{$key}, 'date' => $evidence->{$key}->toDateString(),
                        'datetime' => $evidence->{$key}->copy()->startOfSecond()->toIso8601String(), default => $value,
                    };
                })->all();
            $this->assertSame($evidence->fingerprint, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        }

        $migration = require database_path('migrations/2026_08_24_800000_create_third_party_engagement_offboarding.php');
        $migration->down();
        $this->assertDatabaseHas('third_party_engagement_offboarding_requirements', ['id' => $requirement->id]);
        $this->assertDatabaseHas('third_party_engagement_offboarding_completions', ['id' => $completion->id]);
        $this->assertDatabaseHas('third_party_engagement_offboarding_readiness_reviews', ['id' => $review->id]);

        $this->expectException(\LogicException::class);
        $review->update(['summary' => 'Rewritten evidence']);
    }

    public function test_all_completion_actors_are_excluded_and_renewal_review_can_exit(): void
    {
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $manager = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        $firstCompleter = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        $latestCompleter = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        $reviewer = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        $exitApprover = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));

        Sanctum::actingAs($manager);
        $requirementId = $this->postJson("/api/third-party-engagements/{$engagement->id}/offboarding-requirements", [
            'category' => 'data', 'title' => 'Return retained data', 'acceptance_criteria' => 'Return is attributable.',
            'owner_id' => User::factory()->create()->id, 'due_at' => today()->addMonth()->toDateString(), 'required' => true,
        ])->assertCreated()->json('data.id');
        foreach ([$firstCompleter, $latestCompleter] as $actor) {
            Sanctum::actingAs($actor);
            $this->postJson("/api/third-party-engagement-offboarding-requirements/{$requirementId}/complete", ['completion_summary' => "Completion by {$actor->id}."])->assertCreated();
        }
        Sanctum::actingAs($firstCompleter);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/offboarding-readiness-reviews", ['decision' => 'ready', 'summary' => 'Self review.'])->assertForbidden();

        Sanctum::actingAs($manager);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", ['status' => 'renewal_review', 'summary' => 'Exit considered during renewal.'])->assertOk();
        $this->postJson("/api/third-party-engagements/{$engagement->id}/offboarding-requirements", [
            'category' => 'knowledge', 'title' => 'Retain renewal-exit record', 'acceptance_criteria' => 'Record is retained.',
            'owner_id' => User::factory()->create()->id, 'due_at' => today()->addMonth()->toDateString(), 'required' => false,
        ])->assertCreated();
        $this->actingAs($manager, 'web');
        Livewire::test(EngagementsRelationManager::class, ['ownerRecord' => $engagement->vendor, 'pageClass' => ViewThirdPartyRisk::class])
            ->assertTableActionVisible('define_offboarding_requirement', $engagement->refresh())
            ->assertTableActionVisible('complete_offboarding_requirement', $engagement->refresh())
            ->assertTableActionVisible('review_offboarding_readiness', $engagement->refresh());
        Sanctum::actingAs($reviewer);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/offboarding-readiness-reviews", ['decision' => 'ready', 'summary' => 'Renewal-review exit scope is ready.'])->assertCreated();
        Sanctum::actingAs($exitApprover);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", ['status' => 'exited', 'summary' => 'Exited during renewal review.', 'exit_summary' => 'Exit approved.', 'data_disposition_statement' => 'Provider statement retained.'])->assertOk();
    }
}
