<?php

namespace Tests\Feature;

use App\Enums\Applicability;
use App\Enums\ImplementationStatus;
use App\Enums\RiskDomain;
use App\Enums\RiskGovernanceDecision;
use App\Filament\Exports\RiskExporter;
use App\Filament\Resources\RiskPortfolioResource;
use App\Filament\Resources\RiskPortfolioResource\Pages\ViewRiskPortfolio;
use App\Filament\Resources\RiskPortfolioResource\RelationManagers\GovernanceReviewsRelationManager;
use App\Mcp\EntityConfig;
use App\Mcp\Tools\InspectRiskPortfolioTool;
use App\Models\Asset;
use App\Models\Audit;
use App\Models\BusinessService;
use App\Models\Control;
use App\Models\DataRequest;
use App\Models\DataRequestResponse;
use App\Models\FileAttachment;
use App\Models\Implementation;
use App\Models\Risk;
use App\Models\RiskGovernanceIssue;
use App\Models\RiskGovernanceReviewEvidence;
use App\Models\User;
use App\Services\RiskPortfolioContextManager;
use App\Services\RiskPortfolioManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RiskPortfolioGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        EntityConfig::clearCache();
        Storage::fake('private');
    }

    public function test_enterprise_risk_profile_requires_strategic_context_and_supports_acceptance(): void
    {
        $manager = $this->manager();
        $risk = Risk::factory()->create(['domain' => RiskDomain::Enterprise, 'residual_likelihood' => 2, 'residual_impact' => 3]);
        Sanctum::actingAs($manager);

        $this->putJson("/api/risks/{$risk->id}/governance-profile", $this->profilePayload())
            ->assertUnprocessable()->assertJsonValidationErrors(['strategic_objective']);
        $this->putJson("/api/risks/{$risk->id}/governance-profile", $this->profilePayload() + ['strategic_objective' => 'Protect recurring revenue while entering regulated markets.'])
            ->assertOk()->assertJsonPath('data.appetite_threshold', 8)
            ->assertJsonPath('risk.portfolio_governance_status', 'review_required');

        $this->postJson("/api/risks/{$risk->id}/governance-reviews", $this->reviewPayload('accepted'))
            ->assertCreated()->assertJsonPath('data.residual_score_snapshot', 6)
            ->assertJsonPath('data.appetite_threshold_snapshot', 8)
            ->assertJsonPath('risk.portfolio_governance_status', 'accepted');
    }

    public function test_operational_risk_profile_requires_business_service_context(): void
    {
        $manager = $this->manager();
        $risk = Risk::factory()->create(['domain' => RiskDomain::Operational]);
        $service = BusinessService::factory()->create();
        Sanctum::actingAs($manager);

        $this->putJson("/api/risks/{$risk->id}/governance-profile", $this->profilePayload())
            ->assertUnprocessable()->assertJsonValidationErrors(['business_service_id']);
        $this->putJson("/api/risks/{$risk->id}/governance-profile", $this->profilePayload() + ['business_service_id' => $service->id])
            ->assertOk()->assertJsonPath('data.business_service_id', $service->id);
    }

    public function test_technology_risk_requires_asset_and_control_implementation_context(): void
    {
        $manager = $this->manager();
        $risk = Risk::factory()->create(['domain' => RiskDomain::Technology, 'residual_likelihood' => 2, 'residual_impact' => 3]);
        Sanctum::actingAs($manager);

        $this->putJson("/api/risks/{$risk->id}/governance-profile", $this->profilePayload())
            ->assertUnprocessable()->assertJsonValidationErrors(['assets', 'implementations']);

        $risk->assets()->attach(Asset::factory()->create(['asset_tag' => 'TECH-001', 'name' => 'Payments API']));
        $incomplete = Implementation::factory()->create(['status' => ImplementationStatus::PARTIAL]);
        $incomplete->controls()->attach(Control::factory()->create(['applicability' => Applicability::APPLICABLE]));
        $risk->implementations()->attach($incomplete);
        $this->putJson("/api/risks/{$risk->id}/governance-profile", $this->profilePayload())
            ->assertUnprocessable()->assertJsonValidationErrors(['implementations']);

        $implementation = Implementation::factory()->create(['status' => ImplementationStatus::FULL]);
        $implementation->controls()->attach(Control::factory()->create(['applicability' => Applicability::APPLICABLE]));
        $risk->implementations()->attach($implementation);
        $this->putJson("/api/risks/{$risk->id}/governance-profile", $this->profilePayload())
            ->assertOk()->assertJsonPath('risk.portfolio_governance_status', 'review_required');
        $this->postJson("/api/risks/{$risk->id}/governance-reviews", $this->reviewPayload('accepted'))
            ->assertCreated()->assertJsonPath('risk.portfolio_governance_status', 'accepted');
        $implementation->controls()->detach();
        $this->assertSame('re_review_required', $risk->fresh()->portfolio_governance_status);
    }

    public function test_risk_above_appetite_cannot_be_accepted_and_treatment_opens_issue(): void
    {
        $manager = $this->manager();
        $risk = Risk::factory()->create(['domain' => RiskDomain::Enterprise, 'residual_likelihood' => 4, 'residual_impact' => 4]);
        Sanctum::actingAs($manager);
        $this->putJson("/api/risks/{$risk->id}/governance-profile", $this->profilePayload() + ['strategic_objective' => 'Protect service margin.'])->assertOk();

        $this->postJson("/api/risks/{$risk->id}/governance-reviews", $this->reviewPayload('accepted'))
            ->assertUnprocessable()->assertJsonValidationErrors(['decision']);
        $this->postJson("/api/risks/{$risk->id}/governance-reviews", $this->reviewPayload('mitigate'))
            ->assertCreated()->assertJsonPath('data.issue.status', 'open')
            ->assertJsonPath('risk.portfolio_governance_status', 'action_required');
        $issue = RiskGovernanceIssue::query()->where('risk_id', $risk->id)->firstOrFail();
        $this->assertDatabaseHas('governance_issue_lifecycles', ['issue_type' => RiskGovernanceIssue::class, 'issue_id' => $issue->id, 'status' => 'open']);
    }

    public function test_material_risk_or_profile_change_requires_new_review(): void
    {
        $manager = $this->manager();
        $risk = $this->reviewedEnterpriseRisk($manager);

        $risk->update(['residual_likelihood' => 3]);
        $this->assertSame('re_review_required', $risk->fresh()->portfolio_governance_status);
        $risk->update(['residual_likelihood' => 2]);
        $this->assertSame('accepted', $risk->fresh()->portfolio_governance_status);
        $risk->governanceProfile->update(['appetite_threshold' => 7]);
        $this->assertSame('re_review_required', $risk->fresh()->portfolio_governance_status);
    }

    public function test_governed_context_mapping_mutations_invalidate_and_restore_the_review(): void
    {
        $manager = $this->manager();
        $risk = $this->reviewedEnterpriseRisk($manager);
        $asset = Asset::factory()->create(['asset_tag' => 'ERM-CTX-001', 'name' => 'Enterprise context asset']);
        $context = app(RiskPortfolioContextManager::class);

        $context->attachAsset($risk, $asset);
        $this->assertSame('re_review_required', $risk->fresh()->portfolio_governance_status);
        $context->detachAssets($risk, [$asset]);
        $this->assertSame('accepted', $risk->fresh()->portfolio_governance_status);

        $implementation = Implementation::factory()->create();
        $firstControl = Control::factory()->create();
        $secondControl = Control::factory()->create();
        $implementation->controls()->attach($firstControl);
        $context->attachControls($implementation, [$secondControl]);
        $this->assertEqualsCanonicalizing(
            [$firstControl->id, $secondControl->id],
            $implementation->controls()->pluck('controls.id')->all(),
        );
    }

    public function test_reviews_are_append_only_and_attributable(): void
    {
        $manager = $this->manager();
        $risk = $this->reviewedEnterpriseRisk($manager);
        $review = $risk->governanceReviews()->firstOrFail();

        $this->assertSame($manager->id, $review->reviewed_by);
        $this->assertSame('Protect recurring revenue.', $review->governance_snapshot['profile']['strategic_objective']);
        $risk->governanceProfile->update(['strategic_objective' => 'Changed objective.']);
        $this->assertSame('Protect recurring revenue.', $review->fresh()->governance_snapshot['profile']['strategic_objective']);
        $this->expectException(\LogicException::class);
        $review->delete();
    }

    public function test_governance_review_can_bind_authorized_accepted_audit_evidence(): void
    {
        $manager = $this->manager();
        $attachment = $this->acceptedEvidence($manager, 'risk-review/appetite.pdf', 'risk review evidence bytes');
        $risk = Risk::factory()->create(['domain' => RiskDomain::Enterprise, 'residual_likelihood' => 2, 'residual_impact' => 3]);
        $risk->governanceProfile()->create(array_merge($this->profilePayload(), ['owner_id' => $manager->id, 'strategic_objective' => 'Protect recurring revenue.']));
        Sanctum::actingAs($manager);

        $reviewId = $this->postJson("/api/risks/{$risk->id}/governance-reviews", $this->reviewPayload('accepted') + [
            'evidence_attachment_ids' => [$attachment->id],
        ])->assertCreated()
            ->assertJsonPath('data.evidence.0.file_attachment_id', $attachment->id)
            ->assertJsonPath('data.evidence.0.sha256', hash('sha256', 'risk review evidence bytes'))
            ->json('data.id');

        $evidence = RiskGovernanceReviewEvidence::query()->firstOrFail();
        $this->assertSame($reviewId, $evidence->risk_governance_review_id);
        $this->assertSame('Accepted', $evidence->response_status_snapshot);
        $migration = require database_path('migrations/2026_08_24_290000_create_risk_governance_review_evidence.php');
        $migration->up();
        $migration->down();
        $this->assertDatabaseHas('risk_governance_review_evidence', ['id' => $evidence->id, 'sha256' => $evidence->sha256]);

        Storage::disk('private')->put($attachment->file_path, 'later source replacement');
        $this->actingAs($manager, 'web')->get(route('risk-governance-review-evidence.download', $evidence))
            ->assertSuccessful()->assertStreamedContent('risk review evidence bytes');
        $this->actingAs(User::factory()->create(), 'web')->get(route('risk-governance-review-evidence.download', $evidence))
            ->assertForbidden();

        $foreign = $this->acceptedEvidence(User::factory()->create(), 'risk-review/foreign.pdf', 'foreign bytes');
        $retainedBefore = Storage::disk('private')->allFiles('governed-evidence/risk-governance-review');
        Sanctum::actingAs($manager);
        $this->postJson("/api/risks/{$risk->id}/governance-reviews", $this->reviewPayload('accepted') + [
            'evidence_attachment_ids' => [$attachment->id, $foreign->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('evidence_attachment_ids.1');
        $this->assertDatabaseCount('risk_governance_reviews', 1);
        $this->assertSame($retainedBefore, Storage::disk('private')->allFiles('governed-evidence/risk-governance-review'));

        try {
            $evidence->delete();
            $this->fail('Risk-review evidence was deletable.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('risk_governance_review_evidence', ['id' => $evidence->id]);
        }
        try {
            $attachment->delete();
            $this->fail('A governed source attachment was deletable.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('file_attachments', ['id' => $attachment->id]);
        }

        $owner = User::factory()->create();
        $risk->governanceProfile->update(['owner_id' => $owner->id]);
        $review = $risk->governanceReviews()->firstOrFail();
        $this->actingAs($owner, 'web');
        Livewire::test(GovernanceReviewsRelationManager::class, [
            'ownerRecord' => $risk->fresh(), 'pageClass' => ViewRiskPortfolio::class,
        ])->assertCanSeeTableRecords([$review])
            ->assertTableColumnStateSet('evidence_count', 0, $review)
            ->assertTableActionHidden('inspect_evidence', $review);
    }

    public function test_review_service_reauthorizes_before_creating_review_or_evidence(): void
    {
        $manager = $this->manager();
        $risk = Risk::factory()->create(['domain' => RiskDomain::Enterprise]);
        $risk->governanceProfile()->create(array_merge($this->profilePayload(), ['owner_id' => $manager->id, 'strategic_objective' => 'Protect recurring revenue.']));

        try {
            app(RiskPortfolioManager::class)->review(
                $risk, User::factory()->create(), RiskGovernanceDecision::Accepted, $this->reviewPayload('accepted'),
            );
            $this->fail('Unauthorized direct service review was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('risk_governance_reviews', 0);
        $this->assertDatabaseCount('risk_governance_review_evidence', 0);
    }

    public function test_governed_risk_domain_cannot_be_reclassified(): void
    {
        $manager = $this->manager();
        $risk = $this->reviewedEnterpriseRisk($manager);

        $this->expectException(\LogicException::class);
        $risk->update(['domain' => RiskDomain::Operational]);
    }

    public function test_third_party_and_unclassified_risks_use_other_governance_workflows(): void
    {
        $manager = $this->manager();
        $risk = Risk::factory()->create(['domain' => RiskDomain::ThirdParty]);
        Sanctum::actingAs($manager);

        $this->putJson("/api/risks/{$risk->id}/governance-profile", $this->profilePayload())
            ->assertUnprocessable()->assertJsonValidationErrors(['domain']);
        $this->assertSame('not_applicable', $risk->portfolio_governance_status);
    }

    public function test_owner_has_scoped_read_only_workspace(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $risk = Risk::factory()->create(['domain' => RiskDomain::Enterprise]);
        $otherRisk = Risk::factory()->create(['domain' => RiskDomain::Operational]);
        $risk->governanceProfile()->create(array_merge($this->profilePayload(), ['owner_id' => $owner->id, 'strategic_objective' => 'Protect margin.']));
        $otherRisk->governanceProfile()->create(array_merge($this->profilePayload(), ['owner_id' => $other->id, 'business_service_id' => BusinessService::factory()->create()->id]));

        $this->actingAs($owner)->get(RiskPortfolioResource::getUrl('index'))->assertOk();
        $this->get(RiskPortfolioResource::getUrl('view', ['record' => $risk]))->assertOk();
        $this->assertSame([$risk->id], RiskPortfolioResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_export_and_mcp_expose_portfolio_governance_evidence(): void
    {
        $manager = $this->manager();
        $risk = $this->reviewedEnterpriseRisk($manager);
        $columns = collect(RiskExporter::getColumns())->map->getName();
        $this->assertContains('portfolio_governance_status', $columns);
        $this->assertContains('governanceProfile.owner.name', $columns);
        $this->assertContains('latestGovernanceReview.decision', $columns);
        $exported = RiskExporter::modifyQuery(Risk::query()->whereKey($risk))->firstOrFail();
        $this->assertTrue($exported->relationLoaded('governanceProfile'));
        $this->assertSame('accepted', $exported->portfolio_governance_status);

        $config = EntityConfig::get('risk');
        $this->assertContains('governanceProfile', $config['detail_relations']);
        $this->assertContains('governanceReviews', $config['detail_relations']);

        $this->actingAs($manager);
        $response = (new InspectRiskPortfolioTool)->handle(new Request(['risk_id' => $risk->id]));
        $payload = json_decode((string) $response->content(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame('enterprise', $payload['risk']['domain']);
        $this->assertSame(8, $payload['risk']['profile']['appetite_threshold']);
        $this->assertSame('accepted', $payload['risk']['reviews'][0]['decision']);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('Manage Risk Portfolio');

        return $user;
    }

    private function profilePayload(): array
    {
        return ['owner_id' => User::factory()->create()->id, 'appetite_threshold' => 8, 'review_frequency' => 'quarterly', 'next_review_at' => now()->addMonths(3), 'context_notes' => 'Reviewed by the accountable business owner.'];
    }

    private function reviewPayload(string $decision): array
    {
        return ['decision' => $decision, 'summary' => 'Current exposure and treatment were reviewed against appetite.', 'evidence_reference' => 'RISK-REVIEW-2026-Q3', 'next_review_at' => now()->addMonths(3)];
    }

    private function reviewedEnterpriseRisk(User $manager): Risk
    {
        $risk = Risk::factory()->create(['domain' => RiskDomain::Enterprise, 'residual_likelihood' => 2, 'residual_impact' => 3]);
        $risk->governanceProfile()->create(array_merge($this->profilePayload(), ['owner_id' => $manager->id, 'strategic_objective' => 'Protect recurring revenue.']));
        app(RiskPortfolioManager::class)->review($risk, $manager, RiskGovernanceDecision::Accepted, $this->reviewPayload('accepted'));

        return $risk;
    }

    private function acceptedEvidence(User $auditManager, string $path, string $contents): FileAttachment
    {
        Storage::disk('private')->put($path, $contents);
        $audit = Audit::factory()->create(['manager_id' => $auditManager->id]);
        $request = DataRequest::factory()->create([
            'audit_id' => $audit->id, 'created_by_id' => $auditManager->id, 'assigned_to_id' => $auditManager->id,
        ]);
        $response = DataRequestResponse::factory()->accepted()->create([
            'data_request_id' => $request->id, 'requester_id' => $auditManager->id, 'requestee_id' => $auditManager->id,
        ]);

        return FileAttachment::query()->create([
            'data_request_response_id' => $response->id, 'audit_id' => $audit->id,
            'file_name' => basename($path), 'file_path' => $path, 'file_size' => strlen($contents),
            'description' => 'Governed risk-review evidence', 'uploaded_by' => $auditManager->id,
        ]);
    }
}
