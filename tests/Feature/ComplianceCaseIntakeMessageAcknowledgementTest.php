<?php

namespace Tests\Feature;

use App\ComplianceCases\ComplianceCaseIntakeCorrespondenceManager;
use App\ComplianceCases\ComplianceCaseIntakeManager;
use App\ComplianceCases\ComplianceCaseIntakeMessageAcknowledgementManager;
use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCaseIntakeAudience;
use App\Enums\ComplianceCasePriority;
use App\Filament\Resources\ComplianceCaseIntakeResource\Pages\ViewComplianceCaseIntake;
use App\Filament\Resources\ComplianceCaseIntakeResource\RelationManagers\MessagesRelationManager;
use App\Models\ComplianceCaseIntakeMessageAcknowledgement;
use App\Models\User;
use App\Support\CanonicalJson;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class ComplianceCaseIntakeMessageAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.compliance_cases', true);
    }

    public function test_only_exact_active_reporter_acknowledges_manager_reporter_message_once(): void
    {
        $reporter = User::factory()->create();
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $other = User::factory()->create();
        $intake = app(ComplianceCaseIntakeManager::class)->submit($reporter, [
            'title' => 'Reporter acknowledgement', 'category' => ComplianceCaseCategory::Other->value,
            'priority' => ComplianceCasePriority::Medium->value, 'allegation' => 'A governed concern.',
            'source_channel' => 'Authenticated employee portal',
        ]);
        $correspondence = app(ComplianceCaseIntakeCorrespondenceManager::class);
        $managerMessage = $correspondence->record($manager, $intake, [
            'audience' => ComplianceCaseIntakeAudience::Reporter->value, 'message' => 'We received your additional context.',
        ]);
        $internal = $correspondence->record($manager, $intake, [
            'audience' => ComplianceCaseIntakeAudience::Internal->value, 'message' => 'Internal handling note.',
        ]);
        $reporterMessage = $correspondence->record($reporter, $intake, [
            'audience' => ComplianceCaseIntakeAudience::Reporter->value, 'message' => 'Reporter-authored follow-up.',
        ]);

        $service = app(ComplianceCaseIntakeMessageAcknowledgementManager::class);
        $acknowledgement = $service->acknowledge($reporter, $managerMessage);
        $this->assertSame($reporter->id, $acknowledgement->recipient_id);
        $this->assertSame($managerMessage->fingerprint, $acknowledgement->message_snapshot['fingerprint']);
        $this->assertSame(hash('sha256', CanonicalJson::encode($service->payload($acknowledgement))), $acknowledgement->fingerprint);

        $this->actingAs($reporter)->postJson("/api/compliance-case-intake-messages/{$managerMessage->id}/acknowledge")->assertUnprocessable();
        $this->actingAs($other)->postJson("/api/compliance-case-intake-messages/{$managerMessage->id}/acknowledge")->assertForbidden();
        $this->actingAs($reporter)->postJson("/api/compliance-case-intake-messages/{$internal->id}/acknowledge")->assertForbidden();
        $this->actingAs($reporter)->postJson("/api/compliance-case-intake-messages/{$reporterMessage->id}/acknowledge")->assertUnprocessable();

        $this->actingAs($reporter)->getJson("/api/compliance-case-intakes/{$intake->id}/messages")->assertOk()
            ->assertJsonPath('data.0.acknowledgement.acknowledged_at', $acknowledgement->acknowledged_at->toJSON())
            ->assertJsonPath('data.0.acknowledgement.fingerprint', $acknowledgement->fingerprint)
            ->assertJsonMissingPath('data.0.acknowledgement.recipient_snapshot')
            ->assertJsonMissingPath('data.0.acknowledgement.message_snapshot');
        $this->actingAs($manager)->getJson("/api/compliance-case-intakes/{$intake->id}/messages")->assertOk()
            ->assertJsonPath('data.0.acknowledgement.message_snapshot.fingerprint', $managerMessage->fingerprint);
        Livewire::actingAs($manager)->test(MessagesRelationManager::class, [
            'ownerRecord' => $intake, 'pageClass' => ViewComplianceCaseIntake::class,
        ])->assertSee($acknowledgement->fingerprint)->mountTableAction('inspect', $managerMessage);
        $operatorEvidence = view('filament.compliance-case-intake-message', [
            'message' => $managerMessage->fresh()->load(['actor', 'acknowledgement.recipient']),
        ])->render();
        $this->assertStringContainsString($acknowledgement->recipient_snapshot['email'], $operatorEvidence);
        $this->assertStringContainsString($managerMessage->fingerprint, $operatorEvidence);

        $acknowledgement->fingerprint = str_repeat('a', 64);
        $this->expectException(\LogicException::class);
        $acknowledgement->save();
    }

    public function test_acknowledgement_factory_database_uniqueness_and_retained_migration_are_coherent(): void
    {
        $acknowledgement = ComplianceCaseIntakeMessageAcknowledgement::factory()->create();
        $service = app(ComplianceCaseIntakeMessageAcknowledgementManager::class);
        $this->assertSame(hash('sha256', CanonicalJson::encode($service->payload($acknowledgement))), $acknowledgement->fingerprint);
        try {
            DB::table('compliance_case_intake_message_acknowledgements')->insert([
                'compliance_case_intake_message_id' => $acknowledgement->compliance_case_intake_message_id,
                'recipient_id' => $acknowledgement->recipient_id,
                'recipient_snapshot' => $acknowledgement->getRawOriginal('recipient_snapshot'),
                'message_snapshot' => $acknowledgement->getRawOriginal('message_snapshot'),
                'acknowledged_at' => $acknowledgement->acknowledged_at,
                'fingerprint' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('Expected database uniqueness to reject a second acknowledgement for the same message.');
        } catch (QueryException) {
            $this->assertSame(1, ComplianceCaseIntakeMessageAcknowledgement::query()->count());
        }

        $migration = require database_path('migrations/2026_08_25_130000_create_compliance_case_intake_message_acknowledgements.php');
        $migration->up();
        $migration->down();
        $this->assertTrue(Schema::hasTable('compliance_case_intake_message_acknowledgements'));
        $this->assertDatabaseHas('compliance_case_intake_message_acknowledgements', ['id' => $acknowledgement->id]);
    }
}
