<?php

namespace Tests\Feature;

use App\Filament\Exports\RegulatoryChangeAssessmentExporter;
use App\Filament\Resources\RegulatoryRequirementResource\Pages\ViewRegulatoryRequirement;
use App\Filament\Resources\RegulatoryRequirementResource\RelationManagers\AssessmentsRelationManager;
use App\Filament\Resources\RegulatoryRequirementResource\RelationManagers\VersionsRelationManager;
use App\Models\Control;
use App\Models\Policy;
use App\Models\RegulatoryChangeAssessment;
use App\Models\RegulatoryRequirement;
use App\Models\RegulatoryRequirementVersion;
use App\Models\User;
use App\PolicyCompliance\RegulatoryChangeManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RegulatoryChangeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_editor_registers_source_and_publishes_versioned_requirement_with_mappings(): void
    {
        $manager = $this->manager();
        $owner = User::factory()->create();
        $policy = Policy::factory()->create();
        $control = Control::factory()->create();
        Sanctum::actingAs($manager);

        $sourceId = $this->postJson('/api/regulatory-sources', $this->sourcePayload($owner))
            ->assertCreated()->assertJsonPath('data.created_by', $manager->id)->json('data.id');
        $payload = $this->requirementPayload($owner, $policy, $control);
        $requirementId = $this->postJson("/api/regulatory-sources/{$sourceId}/requirements", $payload + ['version' => 99])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $requirementId = $this->postJson("/api/regulatory-sources/{$sourceId}/requirements", $payload)
            ->assertCreated()->assertJsonPath('data.latest_version.version', 1)
            ->assertJsonPath('data.latest_version.policy_ids.0', $policy->id)
            ->assertJsonPath('data.latest_version.control_ids.0', $control->id)->json('data.id');
        $version = RegulatoryRequirement::query()->findOrFail($requirementId)->latestVersion;
        $this->assertSame(64, strlen($version->content_fingerprint));
        $this->assertSame($policy->name, data_get($version->policy_snapshots, '0.name'));
        $this->assertSame($control->title, data_get($version->control_snapshots, '0.title'));
        $this->assertSame('assessment_required', RegulatoryRequirement::query()->findOrFail($requirementId)->governance_status);

        $this->postJson("/api/regulatory-requirements/{$requirementId}/versions", array_merge($payload, [
            'change_type' => 'amendment', 'title' => 'Updated breach-notification deadline',
            'requirement_text' => 'Notify the authority within the amended statutory deadline.',
        ]))->assertCreated()->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('regulatory_requirement_versions', 2);
    }

    public function test_current_version_receives_attributable_change_assessment_and_immutable_snapshots(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');
        [$manager, $owner, $source, $requirement, $version, $policy, $control] = $this->governedRequirement();
        Sanctum::actingAs($owner);
        $payload = [
            'applicability' => 'applicable', 'impact' => 'high',
            'summary' => 'The amended deadline applies to the organization.',
            'rationale' => 'Processing and establishment criteria are met.',
            'action_owner_id' => $owner->id, 'action_due_at' => '2026-08-30',
        ];
        $assessmentId = $this->postJson("/api/regulatory-requirement-versions/{$version->id}/assessments", $payload)
            ->assertCreated()->assertJsonPath('data.assessment_version', 1)
            ->assertJsonPath('data.policy_snapshots.0.id', $policy->id)
            ->assertJsonPath('data.control_snapshots.0.id', $control->id)
            ->assertJsonPath('data.requirement_snapshot.version.content_fingerprint', $version->content_fingerprint)
            ->json('data.id');
        $this->postJson("/api/regulatory-requirement-versions/{$version->id}/assessments", $payload)
            ->assertCreated()->assertJsonPath('data.assessment_version', 2);
        $assessment = RegulatoryChangeAssessment::query()->findOrFail($assessmentId);
        $this->assertSame($version->policy_snapshots, $assessment->policy_snapshots);
        $this->assertSame($version->control_snapshots, $assessment->control_snapshots);
        $source->update(['title' => 'Renamed live source']);
        $policy->update(['name' => 'Renamed live policy']);
        $this->assertNotSame('Renamed live source', data_get($assessment->requirement_snapshot, 'source.title'));
        $this->assertNotSame('Renamed live policy', data_get($assessment->policy_snapshots, '0.name'));
        try {
            $assessment->update(['summary' => 'Rewritten']);
            $this->fail('Regulatory assessment history was mutable.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('regulatory_change_assessments', ['id' => $assessmentId, 'summary' => $payload['summary']]);
        }
        Carbon::setTestNow('2026-09-01 12:00:00');
        $this->assertSame('action_overdue', $requirement->fresh()->governance_status);
    }

    public function test_assessment_validates_current_version_applicability_and_action_contract(): void
    {
        [$manager, $owner, , $requirement, $version] = $this->governedRequirement();
        Sanctum::actingAs($manager);
        $this->postJson("/api/regulatory-requirement-versions/{$version->id}/assessments", [
            'applicability' => 'applicable', 'impact' => 'critical', 'summary' => 'Critical change.', 'rationale' => 'Applies.',
        ])->assertUnprocessable()->assertJsonValidationErrors('action_owner_id');
        $this->postJson("/api/regulatory-requirement-versions/{$version->id}/assessments", [
            'applicability' => 'not_applicable', 'impact' => 'low', 'summary' => 'Does not apply.', 'rationale' => 'Outside jurisdiction.',
            'action_owner_id' => $owner->id, 'action_due_at' => now()->addWeek()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('action_owner_id');
        $this->postJson("/api/regulatory-requirements/{$requirement->id}/versions", array_merge(
            $this->versionPayload(), ['change_type' => 'guidance', 'title' => 'Interpretive guidance'],
        ))->assertCreated();
        $this->postJson("/api/regulatory-requirements/{$requirement->id}/versions", array_merge(
            $this->versionPayload(), ['change_type' => 'guidance', 'effective_at' => 'August 24 2026'],
        ))->assertUnprocessable()->assertJsonValidationErrors('effective_at');
        $this->postJson("/api/regulatory-requirement-versions/{$version->id}/assessments", [
            'applicability' => 'under_review', 'impact' => 'medium', 'summary' => 'Reviewing.', 'rationale' => 'New guidance.',
            'action_owner_id' => $owner->id, 'action_due_at' => now()->addWeek()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('version');
    }

    public function test_requirement_reads_are_scoped_and_service_reauthorizes_direct_calls(): void
    {
        [$manager, $owner, $source, $requirement, $version] = $this->governedRequirement();
        $outsider = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->getJson('/api/regulatory-requirements')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $requirement->id);
        $this->getJson("/api/regulatory-requirements/{$requirement->id}/versions")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $version->id);
        $this->getJson("/api/regulatory-requirements/{$requirement->id}/assessments")
            ->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($outsider);
        $this->getJson('/api/regulatory-requirements')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/regulatory-requirements/{$requirement->id}/versions")->assertForbidden();
        $this->getJson("/api/regulatory-requirements/{$requirement->id}/assessments")->assertForbidden();

        try {
            app(RegulatoryChangeManager::class)->publishVersion($requirement, $outsider, $this->versionPayload());
            $this->fail('Direct requirement publication bypassed authorization.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        try {
            app(RegulatoryChangeManager::class)->assess($version, $outsider, [
                'applicability' => 'not_applicable', 'impact' => 'low', 'summary' => 'Unauthorized.', 'rationale' => 'Unauthorized.',
            ]);
            $this->fail('Direct change assessment bypassed authorization.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('regulatory_change_assessments', 0);
    }

    public function test_owner_inspects_complete_version_and_assessment_history_and_export_contract(): void
    {
        [, $owner, , $requirement, $version] = $this->governedRequirement();
        $assessment = app(RegulatoryChangeManager::class)->assess($version, $owner, [
            'applicability' => 'not_applicable', 'impact' => 'low',
            'summary' => 'The jurisdiction does not apply.', 'rationale' => 'No establishment or processing nexus.',
        ]);
        $otherOwner = User::factory()->create();
        $otherSource = app(RegulatoryChangeManager::class)->createSource($this->manager(), array_merge($this->sourcePayload($otherOwner), ['code' => 'US-SEC']));
        $otherRequirement = app(RegulatoryChangeManager::class)->createRequirement(
            $otherSource,
            $otherSource->owner,
            $this->requirementPayload($otherOwner, Policy::factory()->create(), Control::factory()->create()),
        );
        $otherAssessment = app(RegulatoryChangeManager::class)->assess($otherRequirement->latestVersion, $otherOwner, [
            'applicability' => 'not_applicable', 'impact' => 'low', 'summary' => 'Other scope.', 'rationale' => 'Other jurisdiction.',
        ]);
        $this->actingAs($owner, 'web');
        Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $requirement, 'pageClass' => ViewRegulatoryRequirement::class])
            ->assertCanSeeTableRecords([$version])->assertTableActionVisible('inspect', $version);
        Livewire::test(AssessmentsRelationManager::class, ['ownerRecord' => $requirement, 'pageClass' => ViewRegulatoryRequirement::class])
            ->assertCanSeeTableRecords([$assessment])->assertCanNotSeeTableRecords([$otherAssessment])
            ->assertTableActionVisible('inspect', $assessment);
        $this->view('filament.regulatory-requirement-version', ['version' => $version])
            ->assertSee($version->requirement_text)->assertSee($version->content_fingerprint)->assertSee(data_get($version->source_snapshot, 'authority'));
        $this->view('filament.regulatory-change-assessment', ['assessment' => $assessment->load('actionOwner')])
            ->assertSee($assessment->summary)->assertSee($assessment->rationale)->assertSee($assessment->content_fingerprint)
            ->assertSee(data_get($assessment->requirement_snapshot, 'source.authority'));
        $columns = collect(RegulatoryChangeAssessmentExporter::getColumns())->map->getName();
        $this->assertContains('requirement_snapshot', $columns);
        $this->assertContains('policy_snapshots', $columns);
        $this->assertContains('control_snapshots', $columns);
        $this->assertContains('content_fingerprint', $columns);
    }

    public function test_factories_create_coherent_regulatory_evidence(): void
    {
        $policy = Policy::factory()->create();
        $control = Control::factory()->create();
        $version = RegulatoryRequirementVersion::factory()->create([
            'policy_ids' => [$policy->id], 'control_ids' => [$control->id],
        ]);
        $assessment = RegulatoryChangeAssessment::factory()->create(['regulatory_requirement_version_id' => $version->id]);

        $this->assertSame($version->requirement->source->id, data_get($version->source_snapshot, 'id'));
        $this->assertSame($policy->id, data_get($version->policy_snapshots, '0.id'));
        $this->assertSame($control->id, data_get($version->control_snapshots, '0.id'));
        $this->assertSame($version->requirement->id, data_get($assessment->requirement_snapshot, 'requirement.id'));
        $this->assertSame($version->policy_snapshots, $assessment->policy_snapshots);
        $this->assertSame($version->control_snapshots, $assessment->control_snapshots);
        $this->assertSame($version->content_fingerprint, $assessment->content_fingerprint);
    }

    private function governedRequirement(): array
    {
        $manager = $this->manager();
        $owner = User::factory()->create();
        $policy = Policy::factory()->create();
        $control = Control::factory()->create();
        $source = app(RegulatoryChangeManager::class)->createSource($manager, $this->sourcePayload($owner));
        $requirement = app(RegulatoryChangeManager::class)->createRequirement($source, $manager, $this->requirementPayload($owner, $policy, $control));

        return [$manager, $owner, $source, $requirement, $requirement->latestVersion, $policy, $control];
    }

    private function sourcePayload(User $owner): array
    {
        return ['code' => 'EU-GDPR', 'title' => 'General Data Protection Regulation', 'authority' => 'European Union', 'jurisdiction' => 'EU', 'reference_url' => 'https://eur-lex.europa.eu/', 'owner_id' => $owner->id, 'status' => 'active'];
    }

    private function requirementPayload(User $owner, Policy $policy, Control $control): array
    {
        return ['code' => 'ART-33', 'owner_id' => $owner->id] + $this->versionPayload() + ['policy_ids' => [$policy->id], 'control_ids' => [$control->id]];
    }

    private function versionPayload(): array
    {
        return ['change_type' => 'new_requirement', 'status' => 'active', 'title' => 'Breach notification', 'requirement_text' => 'Notify the supervisory authority within the statutory deadline.', 'effective_at' => '2026-08-24'];
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('Update Policies');

        return $user;
    }
}
