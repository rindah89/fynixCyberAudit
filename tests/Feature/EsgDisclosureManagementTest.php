<?php

namespace Tests\Feature;

use App\Enums\EsgDataValidationOutcome;
use App\Enums\EsgDisclosureDecision;
use App\Esg\EsgDisclosureManager;
use App\Filament\Resources\EsgDataValidationResource\Pages\ListEsgDataValidations;
use App\Filament\Resources\EsgDisclosureResource\Pages\ListEsgDisclosures;
use App\Filament\Resources\EsgDisclosureResource\Pages\ViewEsgDisclosure;
use App\Filament\Resources\EsgDisclosureResource\RelationManagers\DecisionsRelationManager;
use App\Models\EsgDataValidation;
use App\Models\EsgDisclosure;
use App\Models\EsgDisclosureDecisionRecord;
use App\Models\EsgKpiObservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EsgDisclosureManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.esg_management', true);
    }

    public function test_independent_validation_and_disclosure_approval_bind_exact_evidence(): void
    {
        $observation = EsgKpiObservation::factory()->create();
        $validator = $this->userWith('Validate ESG Data');
        $preparer = $this->userWith('Manage ESG');
        $approver = $this->userWith('Approve ESG Disclosures');
        $service = app(EsgDisclosureManager::class);
        $validation = $service->validateData($validator, $observation, $this->validationData());
        $this->assertSame(EsgDataValidationOutcome::Validated, $validation->outcome);
        $this->assertSame($observation->fingerprint, data_get($validation->observation_snapshot, 'fingerprint'));
        $this->assertSame($validation->fingerprint, $this->validationFingerprint($validation));

        $observation->observer->givePermissionTo('Validate ESG Data');
        try {
            $service->validateData($observation->observer, $observation, $this->validationData());
            $this->fail('Expected independent ESG data validation.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $disclosure = $service->prepare($preparer, $this->disclosureData([$validation->id]));
        $this->assertSame($validation->fingerprint, data_get($disclosure->validation_snapshot, '0.fingerprint'));
        $this->assertSame($disclosure->fingerprint, $this->disclosureFingerprint($disclosure));
        foreach ([$preparer, $validator] as $excluded) {
            $excluded->givePermissionTo('Approve ESG Disclosures');
            try {
                $service->decide($excluded, $disclosure, $this->decisionData());
                $this->fail('Expected independent disclosure approval.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $decision = $service->decide($approver, $disclosure, $this->decisionData());
        $this->assertSame(EsgDisclosureDecision::Approved, $decision->decision);
        $this->assertSame($disclosure->fingerprint, data_get($decision->disclosure_snapshot, 'fingerprint'));
        $this->assertSame($decision->fingerprint, $this->decisionFingerprint($decision));
    }

    public function test_rest_history_server_fields_staleness_and_module_scope_are_governed(): void
    {
        $observation = EsgKpiObservation::factory()->create();
        $validator = $this->userWith('Validate ESG Data');
        $preparer = $this->userWith('Manage ESG');
        $approver = $this->userWith('Approve ESG Disclosures');
        $reader = $this->userWith('Read ESG');
        $outsider = User::factory()->create();

        $this->actingAs($validator)->postJson("/api/esg-kpi-observations/{$observation->id}/validations", $this->validationData() + ['fingerprint' => 'CALLER'])->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $validationId = $this->actingAs($validator)->postJson("/api/esg-kpi-observations/{$observation->id}/validations", $this->validationData())->assertCreated()->json('data.id');
        $this->actingAs($reader)->getJson("/api/esg-kpi-observations/{$observation->id}/validations?per_page=1")->assertOk()->assertJsonPath('data.0.id', $validationId);
        $this->actingAs($preparer)->postJson('/api/esg-disclosures', $this->disclosureData([$validationId]) + ['version' => 9])->assertUnprocessable()->assertJsonValidationErrors('version');
        $disclosureId = $this->actingAs($preparer)->postJson('/api/esg-disclosures', $this->disclosureData([$validationId]))->assertCreated()->json('data.id');
        $this->actingAs($reader)->getJson('/api/esg-disclosures?per_page=1')->assertOk()->assertJsonPath('data.0.id', $disclosureId);
        $this->actingAs($outsider)->getJson("/api/esg-disclosures/{$disclosureId}")->assertForbidden();
        $decisionId = $this->actingAs($approver)->postJson("/api/esg-disclosures/{$disclosureId}/decisions", $this->decisionData())->assertCreated()->json('data.id');
        $this->actingAs($reader)->getJson("/api/esg-disclosures/{$disclosureId}/decisions?per_page=1")->assertOk()->assertJsonPath('data.0.id', $decisionId);
        $validation = EsgDataValidation::query()->findOrFail($validationId);
        $disclosure = EsgDisclosure::query()->findOrFail($disclosureId);
        $decision = EsgDisclosureDecisionRecord::query()->findOrFail($decisionId);
        Livewire::actingAs($reader);
        Livewire::test(ListEsgDataValidations::class)->assertCanSeeTableRecords([$validation]);
        Livewire::test(ListEsgDisclosures::class)->assertCanSeeTableRecords([$disclosure]);
        Livewire::test(DecisionsRelationManager::class, ['ownerRecord' => $disclosure, 'pageClass' => ViewEsgDisclosure::class])->assertCanSeeTableRecords([$decision])->assertTableActionVisible('inspect', $decision);
        $this->view('filament.esg-disclosure-decision', ['decision' => $decision])->assertSee($decision->rationale)->assertSee($decision->fingerprint);

        $observation2 = EsgKpiObservation::factory()->create();
        $valid = app(EsgDisclosureManager::class)->validateData($validator, $observation2, $this->validationData());
        app(EsgDisclosureManager::class)->validateData($validator, $observation2, array_merge($this->validationData(), ['outcome' => EsgDataValidationOutcome::ChangesRequired->value]));
        try {
            app(EsgDisclosureManager::class)->prepare($preparer, $this->disclosureData([$valid->id], 'STALE_ESG_REPORT'));
            $this->fail('Expected stale validation rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('validation_ids', $exception->errors());
        }

        Config::set('enterprise.modules.esg_management', false);
        $this->actingAs($reader)->getJson('/api/esg-disclosures')->assertForbidden();
    }

    public function test_bounds_immutability_factories_and_retained_migration_are_coherent(): void
    {
        $observation = EsgKpiObservation::factory()->create();
        $validator = $this->userWith('Validate ESG Data');
        $preparer = $this->userWith('Manage ESG');
        $service = app(EsgDisclosureManager::class);
        $first = $service->validateData($validator, $observation, $this->validationData());
        foreach (range(2, 20) as $version) {
            EsgDataValidation::query()->create(['esg_kpi_observation_id' => $observation->id, 'version' => $version, 'observation_snapshot' => $first->observation_snapshot, 'completeness_assessment' => 'Bound completeness.', 'accuracy_assessment' => 'Bound accuracy.', 'consistency_assessment' => 'Bound consistency.', 'outcome' => EsgDataValidationOutcome::Validated, 'summary' => 'Bound validation.', 'validated_by' => $validator->id, 'validated_at' => now(), 'fingerprint' => hash('sha256', "esg-validation-{$version}")]);
        }
        try {
            $service->validateData($validator, $observation, $this->validationData());
            $this->fail('Expected validation history bound.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('observation', $exception->errors());
        }
        try {
            $first->update(['summary' => 'Rewrite']);
            $this->fail('Expected append-only validation.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $eligibleObservation = EsgKpiObservation::factory()->create();
        $eligible = $service->validateData($validator, $eligibleObservation, $this->validationData());
        $disclosure = $service->prepare($preparer, $this->disclosureData([$eligible->id], 'BOUND_ESG_REPORT'));
        foreach (range(2, 100) as $version) {
            EsgDisclosure::query()->create(['disclosure_key' => 'BOUND_ESG_REPORT', 'code' => 'BOUND_ESG_REPORT-V'.str_pad((string) $version, 3, '0', STR_PAD_LEFT), 'version' => $version, 'title' => 'Bound disclosure', 'reporting_period_start' => today()->subYear(), 'reporting_period_end' => today(), 'framework_references' => ['GRI 305'], 'narrative' => 'Bound narrative.', 'validation_snapshot' => $disclosure->validation_snapshot, 'prepared_by' => $preparer->id, 'prepared_at' => now(), 'fingerprint' => hash('sha256', "esg-disclosure-{$version}")]);
        }
        try {
            $service->prepare($preparer, $this->disclosureData([$eligible->id], 'BOUND_ESG_REPORT'));
            $this->fail('Expected disclosure version bound.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('disclosure_key', $exception->errors());
        }

        $factoryValidation = EsgDataValidation::factory()->create();
        $factoryDisclosure = EsgDisclosure::factory()->create();
        $factoryDecision = EsgDisclosureDecisionRecord::factory()->create();
        $this->assertSame($factoryValidation->fingerprint, $this->validationFingerprint($factoryValidation));
        $this->assertSame($factoryDisclosure->fingerprint, $this->disclosureFingerprint($factoryDisclosure));
        $this->assertSame($factoryDecision->fingerprint, $this->decisionFingerprint($factoryDecision));
        $migration = require database_path('migrations/2026_08_24_720000_create_esg_data_validation_and_disclosures.php');
        $migration->down();
        $this->assertDatabaseHas('esg_disclosures', ['id' => $disclosure->id, 'fingerprint' => $disclosure->fingerprint]);
    }

    private function validationData(): array
    {
        return ['completeness_assessment' => 'Required reporting-period fields and source references were deliberately reviewed.', 'accuracy_assessment' => 'The submitted value was compared with the referenced operator record.', 'consistency_assessment' => 'Unit, boundary, method, and prior-period presentation were deliberately reviewed.', 'evidence_reference' => 'VALIDATION-WORKPAPER-001', 'outcome' => EsgDataValidationOutcome::Validated->value, 'summary' => 'Independent data-validation judgment for disclosure preparation.'];
    }

    private function disclosureData(array $validationIds, string $key = 'FY2026_ESG_REPORT'): array
    {
        return ['disclosure_key' => $key, 'title' => 'FY2026 governed ESG disclosure', 'reporting_period_start' => today()->subYear()->toDateString(), 'reporting_period_end' => today()->toDateString(), 'framework_references' => ['GRI 305', 'IFRS S1'], 'narrative' => 'Deliberately prepared disclosure narrative based only on the selected validated observations.', 'validation_ids' => $validationIds];
    }

    private function decisionData(): array
    {
        return ['decision' => EsgDisclosureDecision::Approved->value, 'rationale' => 'The version is approved for governed internal publication based on its retained validation evidence.'];
    }

    private function userWith(string $permission): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        return $user;
    }

    private function validationFingerprint(EsgDataValidation $validation): string
    {
        return hash('sha256', json_encode(['esg_kpi_observation_id' => $validation->esg_kpi_observation_id, 'version' => $validation->version, 'observation_snapshot' => $validation->observation_snapshot, 'completeness_assessment' => $validation->completeness_assessment, 'accuracy_assessment' => $validation->accuracy_assessment, 'consistency_assessment' => $validation->consistency_assessment, 'evidence_reference' => $validation->evidence_reference, 'outcome' => $validation->outcome->value, 'summary' => $validation->summary, 'validated_by' => $validation->validated_by, 'validated_at' => $validation->validated_at->toIso8601String()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function disclosureFingerprint(EsgDisclosure $disclosure): string
    {
        return hash('sha256', json_encode(['disclosure_key' => $disclosure->disclosure_key, 'code' => $disclosure->code, 'version' => $disclosure->version, 'title' => $disclosure->title, 'reporting_period_start' => $disclosure->reporting_period_start->toDateString(), 'reporting_period_end' => $disclosure->reporting_period_end->toDateString(), 'framework_references' => $disclosure->framework_references, 'narrative' => $disclosure->narrative, 'validation_snapshot' => $disclosure->validation_snapshot, 'prepared_by' => $disclosure->prepared_by, 'prepared_at' => $disclosure->prepared_at->toIso8601String()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function decisionFingerprint(EsgDisclosureDecisionRecord $decision): string
    {
        return hash('sha256', json_encode(['esg_disclosure_id' => $decision->esg_disclosure_id, 'version' => $decision->version, 'disclosure_snapshot' => $decision->disclosure_snapshot, 'decision' => $decision->decision->value, 'rationale' => $decision->rationale, 'decided_by' => $decision->decided_by, 'decided_at' => $decision->decided_at->toIso8601String()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
