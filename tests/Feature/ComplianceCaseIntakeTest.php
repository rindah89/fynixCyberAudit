<?php

namespace Tests\Feature;

use App\ComplianceCases\ComplianceCaseIntakeManager;
use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCaseIntakeDecision;
use App\Enums\ComplianceCasePriority;
use App\Filament\Resources\ComplianceCaseIntakeResource\Pages\ViewComplianceCaseIntake;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseIntake;
use App\Models\ComplianceCaseIntakeDisposition;
use App\Models\ComplianceCaseIntakeMutex;
use App\Models\User;
use App\Support\CanonicalJson;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ComplianceCaseIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.compliance_cases', true);
    }

    public function test_internal_reporter_submits_and_independent_manager_accepts_a_governed_intake(): void
    {
        $reporter = User::factory()->create();
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $service = app(ComplianceCaseIntakeManager::class);

        $intake = $service->submit($reporter, [
            'title' => 'Potential procurement conflict',
            'category' => ComplianceCaseCategory::ConflictOfInterest->value,
            'priority' => ComplianceCasePriority::High->value,
            'allegation' => 'A bidder may be related to an evaluation-panel member.',
            'source_channel' => 'Authenticated employee portal',
            'source_reference' => 'PROC-2026-44',
            'confidential' => true,
            'reporter_message' => 'Please keep my identity within the authorized case workspace.',
        ]);

        $this->assertMatchesRegularExpression('/^CCI-\d{4}-\d{6}$/', $intake->reference);
        $this->assertNull($intake->decision);
        $this->assertSame(hash('sha256', CanonicalJson::encode($service->submissionPayload($intake))), $intake->fingerprint);

        $decision = $service->decide($manager, $intake, [
            'decision' => ComplianceCaseIntakeDecision::Accepted->value,
            'summary' => 'The concern is within scope and requires governed investigation.',
        ]);

        $this->assertNotNull($decision->compliance_case_id);
        $this->assertSame($intake->id, $decision->intake_snapshot['id']);
        $this->assertSame($intake->fingerprint, $decision->intake_snapshot['fingerprint']);
        $this->assertSame(hash('sha256', CanonicalJson::encode($service->decisionPayload($decision))), $decision->fingerprint);
        $this->assertSame('Compliance case intake', $decision->complianceCase->source_channel);
        $this->assertSame($intake->reference, $decision->complianceCase->source_reference);
        $this->assertSame($reporter->id, $decision->complianceCase->reporter_reference === (string) $reporter->id ? $reporter->id : null);

        $reporterResponse = $this->actingAs($reporter)->getJson('/api/my-compliance-case-intakes')->assertOk();
        $reporterResponse->assertJsonPath('data.0.reference', $intake->reference)
            ->assertJsonPath('data.0.decision.decision', ComplianceCaseIntakeDecision::Accepted->value)
            ->assertJsonMissingPath('data.0.allegation')
            ->assertJsonMissingPath('data.0.reporter_snapshot')
            ->assertJsonMissingPath('data.0.decision.intake_snapshot')
            ->assertJsonMissingPath('data.0.decision.case_snapshot');

        $this->actingAs($manager)->getJson('/api/compliance-case-intakes')->assertOk()
            ->assertJsonPath('data.0.allegation', $intake->allegation)
            ->assertJsonPath('data.0.decision.case_snapshot.case.id', $decision->compliance_case_id);
        Livewire::actingAs($manager)->test(ViewComplianceCaseIntake::class, ['record' => $intake->id])
            ->assertSee($intake->allegation)->assertSee($decision->summary)->assertSee($decision->fingerprint)
            ->assertSee($decision->case_snapshot['opening_event']['fingerprint']);

        $reader = User::factory()->create();
        $reader->givePermissionTo('Read Compliance Cases');
        $this->actingAs($reader)->getJson('/api/compliance-case-intakes')->assertForbidden();

        $this->actingAs($reporter)->postJson("/api/compliance-case-intakes/{$intake->id}/decision", [
            'decision' => ComplianceCaseIntakeDecision::Rejected->value, 'summary' => 'Unauthorized.',
        ])->assertForbidden();
        $this->actingAs($manager)->postJson("/api/compliance-case-intakes/{$intake->id}/decision", [
            'decision' => ComplianceCaseIntakeDecision::Rejected->value, 'summary' => 'Duplicate.',
        ])->assertUnprocessable();
    }

    public function test_intake_submission_fails_closed_when_the_mutex_row_is_missing(): void
    {
        $reporter = User::factory()->create();
        ComplianceCaseIntakeMutex::query()->whereKey(1)->delete();
        try {
            app(ComplianceCaseIntakeManager::class)->submit($reporter, [
                'title' => 'Mutex missing', 'category' => ComplianceCaseCategory::Other->value,
                'priority' => ComplianceCasePriority::Low->value,
                'allegation' => 'A governed allegation.', 'source_channel' => 'Authenticated employee portal',
            ]);
            $this->fail('Expected intake submission to fail closed without the mutex row.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('compliance_case_intakes', 0);
        }
    }

    public function test_intake_rejection_is_terminal_private_and_factory_evidence_reconstructs(): void
    {
        $reporter = User::factory()->create();
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $service = app(ComplianceCaseIntakeManager::class);
        $intake = ComplianceCaseIntake::factory()->for($reporter, 'reporter')->create();

        $decision = $service->decide($manager, $intake, [
            'decision' => ComplianceCaseIntakeDecision::Rejected->value,
            'summary' => 'The report is retained but does not meet the governed case threshold.',
        ]);

        $this->assertNull($decision->compliance_case_id);
        $this->assertSame(hash('sha256', CanonicalJson::encode($service->submissionPayload($intake->refresh()))), $intake->fingerprint);
        $this->assertSame(hash('sha256', CanonicalJson::encode($service->decisionPayload($decision))), $decision->fingerprint);
        $this->actingAs(User::factory()->create())->getJson('/api/my-compliance-case-intakes')->assertOk()->assertJsonCount(0, 'data');
        try {
            $service->decide($reporter, ComplianceCaseIntake::factory()->for($reporter, 'reporter')->create(), [
                'decision' => ComplianceCaseIntakeDecision::Rejected->value, 'summary' => 'Self decision.',
            ]);
            $this->fail('Expected reporter/decider separation.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $migration = require database_path('migrations/2026_08_25_110000_create_compliance_case_intakes.php');
        $migration->up();
        $migration->down();
        $this->assertTrue(Schema::hasTable('compliance_case_intakes'));
        $this->assertTrue(Schema::hasTable('compliance_case_intake_dispositions'));
        $this->assertDatabaseHas('compliance_case_intakes', ['id' => $intake->id, 'fingerprint' => $intake->fingerprint]);
        $this->assertDatabaseHas('compliance_case_intake_dispositions', ['id' => $decision->id, 'fingerprint' => $decision->fingerprint]);

        $defaulted = $service->submit($reporter, [
            'title' => 'Default confidentiality', 'category' => ComplianceCaseCategory::Other->value,
            'priority' => ComplianceCasePriority::Low->value, 'allegation' => 'A deliberately submitted concern.',
            'source_channel' => 'Authenticated employee portal',
        ])->refresh();
        $this->assertTrue($defaulted->confidential);
        $this->assertSame(hash('sha256', CanonicalJson::encode($service->submissionPayload($defaulted))), $defaulted->fingerprint);

        $caseCount = ComplianceCase::query()->count();
        ComplianceCaseIntakeDisposition::creating(function (): never {
            throw new \RuntimeException('Forced disposition persistence failure.');
        });
        try {
            $service->decide($manager, $defaulted, [
                'decision' => ComplianceCaseIntakeDecision::Accepted->value, 'summary' => 'This transaction must roll back.',
            ]);
            $this->fail('Expected the forced disposition failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced disposition persistence failure.', $exception->getMessage());
        }
        $this->assertSame($caseCount, ComplianceCase::query()->count());
        $this->assertDatabaseMissing('compliance_cases', ['source_reference' => $defaulted->reference]);
        $this->assertDatabaseMissing('compliance_case_intake_dispositions', ['compliance_case_intake_id' => $defaulted->id]);

        $intake->title = 'Mutation';
        $this->expectException(\LogicException::class);
        $intake->save();
    }
}
