<?php

namespace Tests\Feature;

use App\Models\ThirdPartyContractRiskReview;
use App\Models\ThirdPartyEngagementOnboardingCompletion;
use App\Models\ThirdPartyEngagementOnboardingReadinessReview;
use App\Models\ThirdPartyEngagementOnboardingRequirement;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ThirdPartyEngagementOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_required_onboarding_controls_and_independent_readiness_gate_activation(): void
    {
        $contractReview = ThirdPartyContractRiskReview::factory()->create();
        $engagement = $contractReview->engagement;
        $manager = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $owner = User::factory()->create();
        $reviewer = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $activator = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));

        Sanctum::actingAs($manager);
        $requirementId = $this->postJson("/api/third-party-engagements/{$engagement->id}/onboarding-requirements", [
            'category' => 'security', 'title' => 'Provision least-privilege production access',
            'acceptance_criteria' => 'Named production accounts use MFA and approved least-privilege roles.',
            'owner_id' => $owner->id, 'due_at' => today()->addMonth()->toDateString(), 'required' => true,
        ])->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');

        Sanctum::actingAs($owner);
        $this->postJson("/api/third-party-engagement-onboarding-requirements/{$requirementId}/complete", [
            'completion_summary' => 'Named accounts, MFA, and approved roles were configured and reviewed.',
            'source_reference' => 'IAM-CHANGE-2042',
        ])->assertCreated()->assertJsonPath('data.version', 1);

        Sanctum::actingAs($reviewer);
        $reviewId = $this->postJson("/api/third-party-engagements/{$engagement->id}/onboarding-readiness-reviews", [
            'decision' => 'ready', 'summary' => 'Every required onboarding control has attributable completion evidence.',
            'next_review_at' => $engagement->next_review_at->toDateString(),
        ])->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');

        $reader = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Read Vendors'));
        Sanctum::actingAs($reader);
        $this->getJson("/api/third-party-engagements/{$engagement->id}/onboarding-requirements?per_page=100")
            ->assertOk()->assertJsonPath('data.0.id', $requirementId)->assertJsonCount(1, 'data.0.completions');
        $this->getJson("/api/third-party-engagements/{$engagement->id}/onboarding-readiness-reviews?per_page=100")
            ->assertOk()->assertJsonPath('data.0.id', $reviewId);

        Sanctum::actingAs($manager);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/onboarding-requirements", ['category' => 'operational', 'title' => 'Publish support contacts', 'acceptance_criteria' => 'Current escalation contacts are recorded.', 'owner_id' => User::factory()->create()->id, 'due_at' => today()->addMonth()->toDateString(), 'required' => false])->assertCreated();
        Sanctum::actingAs($activator);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", ['status' => 'active', 'summary' => 'Stale readiness activation.'])->assertUnprocessable()->assertJsonValidationErrors('onboarding_readiness');
        Sanctum::actingAs($reviewer);
        $reviewId = $this->postJson("/api/third-party-engagements/{$engagement->id}/onboarding-readiness-reviews", ['decision' => 'ready', 'summary' => 'The updated onboarding scope is ready.', 'next_review_at' => $engagement->next_review_at->toDateString()])->assertCreated()->assertJsonPath('data.version', 2)->json('data.id');

        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", ['status' => 'active', 'summary' => 'Reviewer self-activation.'])->assertForbidden();
        Sanctum::actingAs($activator);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/events", ['status' => 'active', 'summary' => 'Activated after independent readiness review.'])
            ->assertOk()->assertJsonPath('data.engagement_snapshot.onboarding_readiness_snapshot.id', $reviewId);
        $this->view('filament.third-party-engagement', ['engagement' => $engagement->fresh(['businessOwner', 'events.actor', 'contractRiskReviews.reviewer', 'dueDiligenceReviews.reviewer', 'onboardingRequirements.owner', 'onboardingRequirements.definer', 'onboardingRequirements.completions.completer', 'onboardingReadinessReviews.reviewer', 'monitoringIndicators.latestObservations'])])
            ->assertSee('Governed onboarding controls')->assertSee('Provision least-privilege production access')->assertSee('Independent onboarding-readiness history')->assertSee('IAM-CHANGE-2042');
    }

    public function test_onboarding_factories_are_reconstructible_and_routine_rollback_retains_history(): void
    {
        $requirement = ThirdPartyEngagementOnboardingRequirement::factory()->create();
        $completion = ThirdPartyEngagementOnboardingCompletion::factory()->create();
        $review = ThirdPartyEngagementOnboardingReadinessReview::factory()->create();

        foreach ([$requirement, $completion, $review] as $evidence) {
            $payload = collect($evidence->getAttributes())
                ->except(['id', 'fingerprint', 'created_at', 'updated_at'])
                ->map(function (mixed $value, string $key) use ($evidence): mixed {
                    $cast = $evidence->getCasts()[$key] ?? null;

                    return match ($cast) {
                        'array' => $evidence->{$key},
                        'boolean' => (bool) $evidence->{$key},
                        'date' => $evidence->{$key}->toDateString(),
                        'datetime' => $evidence->{$key}->copy()->startOfSecond()->toIso8601String(),
                        default => $value,
                    };
                })->all();

            $this->assertSame($evidence->fingerprint, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        }

        $migration = require database_path('migrations/2026_08_24_790000_create_third_party_engagement_onboarding.php');
        $migration->down();

        $this->assertDatabaseHas('third_party_engagement_onboarding_requirements', ['id' => $requirement->id]);
        $this->assertDatabaseHas('third_party_engagement_onboarding_completions', ['id' => $completion->id]);
        $this->assertDatabaseHas('third_party_engagement_onboarding_readiness_reviews', ['id' => $review->id]);

        $this->expectException(\LogicException::class);
        $requirement->update(['title' => 'Rewritten evidence']);
    }

    public function test_onboarding_evidence_requires_a_current_accepted_contract_review(): void
    {
        $contract = ThirdPartyContractRiskReview::factory()->create();
        $engagement = $contract->engagement;
        $manager = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        Sanctum::actingAs($manager);

        DB::table('third_party_contract_risk_reviews')->where('id', $contract->id)->update(['decision' => 'rejected']);

        $this->postJson("/api/third-party-engagements/{$engagement->id}/onboarding-requirements", [
            'category' => 'security', 'title' => 'Rejected-contract control', 'acceptance_criteria' => 'Must not persist.',
            'owner_id' => User::factory()->create()->id, 'due_at' => today()->addMonth()->toDateString(), 'required' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('contract_review');

        $this->assertDatabaseCount('third_party_engagement_onboarding_requirements', 0);
    }
}
