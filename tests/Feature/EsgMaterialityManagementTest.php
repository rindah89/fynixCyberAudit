<?php

namespace Tests\Feature;

use App\Enums\EsgMaterialityDecision;
use App\Enums\EsgTopicStatus;
use App\Esg\EsgMaterialityManager;
use App\Filament\Resources\EsgMaterialTopicResource;
use App\Filament\Resources\EsgMaterialTopicResource\Pages\ViewEsgMaterialTopic;
use App\Filament\Resources\EsgMaterialTopicResource\RelationManagers\AssessmentsRelationManager;
use App\Filament\Resources\EsgMaterialTopicResource\RelationManagers\VersionsRelationManager;
use App\Models\EsgMaterialityAssessment;
use App\Models\EsgMaterialTopic;
use App\Models\EsgMaterialTopicVersion;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EsgMaterialityManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.esg_management', true);
    }

    public function test_topic_registration_and_independent_double_materiality_assessment_are_reconstructible(): void
    {
        $manager = $this->userWith('Manage ESG');
        $owner = $this->userWith('Own ESG Topics');
        $assessor = $this->userWith('Assess ESG');
        $service = app(EsgMaterialityManager::class);
        $topic = $service->register($manager, $this->topicData($owner));

        $this->assertSame(EsgTopicStatus::Draft, $topic->status);
        $this->assertStringStartsWith('ESG-'.now()->format('Y').'-', $topic->code);
        $version = $topic->versions()->firstOrFail();
        $this->assertSame($topic->owner_id, data_get($version->topic_snapshot, 'owner.id'));
        $this->assertSame($version->fingerprint, $this->versionFingerprint($version));

        foreach ([$manager, $owner] as $excluded) {
            try {
                $service->assess($excluded, $topic, $this->assessmentData());
                $this->fail('Expected independent materiality assessment.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        $assessment = $service->assess($assessor, $topic, $this->assessmentData());
        $this->assertSame(EsgMaterialityDecision::Material, $assessment->decision);
        $this->assertSame(EsgTopicStatus::Material, $topic->fresh()->status);
        $this->assertSame($assessment->fingerprint, $this->assessmentFingerprint($assessment));
        $this->assertSame($version->fingerprint, $assessment->topicVersion->fingerprint);

        $snapshot = $assessment->topic_snapshot;
        $topic->update(['name' => 'Changed outside the governed manager']);
        $this->assertSame($snapshot, $assessment->fresh()->topic_snapshot);
    }

    public function test_material_revision_requires_reassessment_and_retirement_is_terminal(): void
    {
        $manager = $this->userWith('Manage ESG');
        $owner = $this->userWith('Own ESG Topics');
        $assessor = $this->userWith('Assess ESG');
        $service = app(EsgMaterialityManager::class);
        $topic = $service->register($manager, $this->topicData($owner));
        $service->assess($assessor, $topic, $this->assessmentData());

        $revision = $service->revise($owner, $topic, [
            'description' => 'Updated material topic boundary and context.',
            'change_summary' => 'Expand the governed material topic boundary.',
        ]);
        $this->assertSame(2, $revision->version);
        $this->assertSame(EsgTopicStatus::ReviewRequired, $topic->fresh()->status);
        $this->assertSame($revision->fingerprint, $this->versionFingerprint($revision));
        $replacement = $service->assess($assessor, $topic, $this->assessmentData(EsgMaterialityDecision::NotMaterial));
        $this->assertSame(EsgTopicStatus::NotMaterial, $topic->fresh()->status);
        $this->assertSame(2, $replacement->version);

        $service->revise($manager, $topic, [
            'status' => EsgTopicStatus::Retired->value,
            'change_summary' => 'Retire the governed topic without rewriting its context.',
        ]);
        $this->assertSame(EsgTopicStatus::Retired, $topic->fresh()->status);
        foreach ([
            fn () => $service->revise($manager, $topic, ['name' => 'Rewrite', 'change_summary' => 'Rewrite']),
            fn () => $service->assess($assessor, $topic, $this->assessmentData()),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Expected retired topic to be terminal.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('topic', $exception->errors());
            }
        }
    }

    public function test_rest_scope_validation_and_operator_history_are_governed(): void
    {
        $manager = $this->userWith('Manage ESG');
        $owner = $this->userWith('Own ESG Topics');
        $assessor = $this->userWith('Assess ESG');
        $reader = $this->userWith('Read ESG');
        $outsider = User::factory()->create();

        $this->actingAs($manager)->postJson('/api/esg-material-topics', $this->topicData($owner) + ['code' => 'CALLER'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $id = $this->actingAs($manager)->postJson('/api/esg-material-topics', $this->topicData($owner))->assertCreated()->json('data.id');
        $topic = EsgMaterialTopic::query()->findOrFail($id);
        $assessmentId = $this->actingAs($assessor)->postJson("/api/esg-material-topics/{$id}/assessments", $this->assessmentData() + ['fingerprint' => 'CALLER'])->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $assessmentId = $this->actingAs($assessor)->postJson("/api/esg-material-topics/{$id}/assessments", $this->assessmentData())->assertCreated()->json('data.id');

        $this->actingAs($reader)->getJson('/api/esg-material-topics?per_page=1')->assertOk()->assertJsonPath('per_page', 1)->assertJsonPath('data.0.id', $id);
        $this->actingAs($owner)->getJson('/api/esg-material-topics')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($outsider)->getJson('/api/esg-material-topics')->assertForbidden();
        try {
            app(EsgMaterialityManager::class)->revise($outsider, $topic, ['name' => 'Probe', 'change_summary' => 'Probe']);
            $this->fail('Unauthorized direct service mutation succeeded.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        Livewire::actingAs($reader);
        Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $topic, 'pageClass' => ViewEsgMaterialTopic::class])
            ->assertCanSeeTableRecords([$topic->versions()->firstOrFail()])
            ->assertTableActionVisible('inspect', $topic->versions()->firstOrFail());
        $assessment = EsgMaterialityAssessment::query()->findOrFail($assessmentId);
        Livewire::test(AssessmentsRelationManager::class, ['ownerRecord' => $topic, 'pageClass' => ViewEsgMaterialTopic::class])
            ->assertCanSeeTableRecords([$assessment])
            ->assertTableActionVisible('inspect', $assessment);
        $this->view('filament.esg-materiality-evidence', ['title' => 'Assessment', 'snapshot' => $assessment->topic_snapshot, 'summary' => $assessment->decision_summary, 'fingerprint' => $assessment->fingerprint])
            ->assertSee($topic->impact_context)->assertSee($assessment->fingerprint);

        Config::set('enterprise.modules.esg_management', false);
        $this->actingAs($reader)->getJson('/api/esg-material-topics')->assertForbidden();
        $this->assertFalse(EsgMaterialTopicResource::shouldRegisterNavigation());
    }

    public function test_append_only_bounds_factories_and_retained_migration_are_enforced(): void
    {
        $manager = $this->userWith('Manage ESG');
        $owner = $this->userWith('Own ESG Topics');
        $assessor = $this->userWith('Assess ESG');
        $service = app(EsgMaterialityManager::class);
        $topic = $service->register($manager, $this->topicData($owner));
        $assessment = $service->assess($assessor, $topic, $this->assessmentData());
        $version = $topic->versions()->firstOrFail();

        foreach ([fn () => $version->update(['change_summary' => 'Rewrite']), fn () => $assessment->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('Retained ESG evidence was mutable.');
            } catch (\LogicException $exception) {
                $this->assertMatchesRegularExpression('/append-only|retained evidence/', $exception->getMessage());
            }
        }

        $factoryVersion = EsgMaterialTopicVersion::factory()->create();
        $factoryAssessment = EsgMaterialityAssessment::factory()->create();
        $this->assertSame($factoryVersion->topic->owner_id, data_get($factoryVersion->topic_snapshot, 'owner.id'));
        $this->assertSame($factoryVersion->fingerprint, $this->versionFingerprint($factoryVersion));
        $this->assertSame($factoryAssessment->fingerprint, $this->assessmentFingerprint($factoryAssessment));

        foreach (range(2, 100) as $number) {
            EsgMaterialTopicVersion::query()->create([
                'esg_material_topic_id' => $topic->id, 'version' => $number,
                'topic_snapshot' => $version->topic_snapshot, 'change_summary' => "Bound version {$number}",
                'recorded_by' => $manager->id, 'recorded_at' => now(), 'fingerprint' => hash('sha256', "esg-version-{$number}"),
            ]);
        }
        try {
            $service->revise($manager, $topic, ['name' => 'Beyond bound', 'change_summary' => 'Beyond bound']);
            $this->fail('Expected topic version bound.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('topic', $exception->errors());
        }

        foreach (range(2, 100) as $number) {
            EsgMaterialityAssessment::query()->create([
                'esg_material_topic_id' => $topic->id, 'version' => $number, 'topic_version_id' => $version->id,
                'topic_snapshot' => $version->topic_snapshot, 'impact_materiality' => 4, 'financial_materiality' => 4,
                'stakeholder_evidence' => 'Bound evidence', 'methodology' => 'Bound method',
                'decision' => EsgMaterialityDecision::Material, 'decision_summary' => 'Bound decision',
                'assessed_by' => $assessor->id, 'assessed_at' => now(), 'next_review_at' => today()->addYear(),
                'fingerprint' => hash('sha256', "esg-assessment-{$number}"),
            ]);
        }
        try {
            $service->assess($assessor, $topic, $this->assessmentData());
            $this->fail('Expected assessment history bound.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assessment', $exception->errors());
        }

        $migration = require database_path('migrations/2026_08_24_700000_create_esg_materiality_management.php');
        $migration->down();
        $this->assertDatabaseHas('esg_materiality_assessments', ['id' => $assessment->id, 'fingerprint' => $assessment->fingerprint]);
    }

    private function topicData(User $owner): array
    {
        return [
            'name' => 'Climate transition and energy', 'pillar' => 'Environmental', 'owner_id' => $owner->id,
            'description' => 'Governed material topic for transition impacts and exposure.',
            'impact_context' => 'Operational emissions and value-chain consequences affect stakeholders.',
            'risk_context' => 'Transition cost and policy exposure may affect enterprise value.',
            'opportunity_context' => 'Efficiency and low-carbon services may create durable value.',
            'stakeholder_groups' => ['Employees', 'Customers', 'Communities'],
            'framework_references' => ['GRI 3', 'IFRS S1'], 'organizational_boundary' => 'Consolidated operations and selected value-chain activities.',
            'source_reference' => 'ESG-SOURCE-001', 'next_review_at' => today()->addYear()->toDateString(),
            'change_summary' => 'Register the initial governed material-topic context.',
        ];
    }

    private function assessmentData(EsgMaterialityDecision $decision = EsgMaterialityDecision::Material): array
    {
        return [
            'impact_materiality' => 5, 'financial_materiality' => 4,
            'stakeholder_evidence' => 'Stakeholder interviews, impact register, and governance workshop evidence.',
            'methodology' => 'Double-materiality scoring on a five-point ordinal scale.',
            'decision' => $decision->value, 'decision_summary' => 'Independent deliberate materiality judgment.',
            'next_review_at' => today()->addYear()->toDateString(),
        ];
    }

    private function userWith(string $permission): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        return $user;
    }

    private function versionFingerprint(EsgMaterialTopicVersion $version): string
    {
        return hash('sha256', json_encode([
            'esg_material_topic_id' => $version->esg_material_topic_id, 'version' => $version->version,
            'topic_snapshot' => $version->topic_snapshot, 'change_summary' => $version->change_summary,
            'recorded_by' => $version->recorded_by, 'recorded_at' => $version->recorded_at->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function assessmentFingerprint(EsgMaterialityAssessment $assessment): string
    {
        return hash('sha256', json_encode([
            'esg_material_topic_id' => $assessment->esg_material_topic_id, 'version' => $assessment->version,
            'topic_version_id' => $assessment->topic_version_id, 'topic_snapshot' => $assessment->topic_snapshot,
            'impact_materiality' => $assessment->impact_materiality, 'financial_materiality' => $assessment->financial_materiality,
            'stakeholder_evidence' => $assessment->stakeholder_evidence, 'methodology' => $assessment->methodology,
            'decision' => $assessment->decision->value, 'decision_summary' => $assessment->decision_summary,
            'assessed_by' => $assessment->assessed_by, 'assessed_at' => $assessment->assessed_at->toIso8601String(),
            'next_review_at' => $assessment->next_review_at->toDateString(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
