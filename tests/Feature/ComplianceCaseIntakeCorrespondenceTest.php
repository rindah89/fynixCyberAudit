<?php

namespace Tests\Feature;

use App\ComplianceCases\ComplianceCaseIntakeCorrespondenceManager;
use App\ComplianceCases\ComplianceCaseIntakeManager;
use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCaseIntakeAudience;
use App\Enums\ComplianceCaseIntakeDecision;
use App\Enums\ComplianceCasePriority;
use App\Filament\Resources\ComplianceCaseIntakeResource;
use App\Filament\Resources\ComplianceCaseIntakeResource\RelationManagers\MessagesRelationManager;
use App\Models\ComplianceCaseIntakeMessage;
use App\Models\User;
use App\Support\CanonicalJson;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ComplianceCaseIntakeCorrespondenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.compliance_cases', true);
    }

    public function test_exact_reporter_and_managers_exchange_audience_scoped_immutable_correspondence(): void
    {
        $reporter = User::factory()->create();
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $other = User::factory()->create();
        $intakes = app(ComplianceCaseIntakeManager::class);
        $messages = app(ComplianceCaseIntakeCorrespondenceManager::class);
        $intake = $intakes->submit($reporter, [
            'title' => 'Potential retaliation', 'category' => ComplianceCaseCategory::Retaliation->value,
            'priority' => ComplianceCasePriority::High->value, 'allegation' => 'A deliberate authenticated concern.',
            'source_channel' => 'Authenticated employee portal',
        ]);
        $decision = $intakes->decide($manager, $intake, [
            'decision' => ComplianceCaseIntakeDecision::Accepted->value, 'summary' => 'Open a governed case.',
        ]);

        $reporterMessage = $messages->record($reporter, $intake, [
            'audience' => ComplianceCaseIntakeAudience::Reporter->value,
            'message' => 'I can provide the meeting date if it assists the review.',
        ]);
        $managerMessage = $messages->record($manager, $intake, [
            'audience' => ComplianceCaseIntakeAudience::Reporter->value,
            'message' => 'Please provide the date without sending unrelated personal information.',
        ]);
        $internalMessage = $messages->record($manager, $intake, [
            'audience' => ComplianceCaseIntakeAudience::Internal->value,
            'message' => 'Internal note: preserve the current intake and opening-event fingerprints.',
        ]);

        $this->assertSame(1, $reporterMessage->version);
        $this->assertSame(2, $managerMessage->version);
        $this->assertSame(3, $internalMessage->version);
        $this->assertSame($decision->fingerprint, $internalMessage->disposition_snapshot['fingerprint']);
        $this->assertSame(hash('sha256', CanonicalJson::encode($messages->payload($internalMessage))), $internalMessage->fingerprint);

        $this->actingAs($reporter)->getJson("/api/compliance-case-intakes/{$intake->id}/messages")->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonFragment(['message' => $reporterMessage->message])
            ->assertJsonFragment(['message' => $managerMessage->message])
            ->assertJsonMissing(['message' => $internalMessage->message])
            ->assertJsonMissingPath('data.0.intake_snapshot')
            ->assertJsonMissingPath('data.0.disposition_snapshot')
            ->assertJsonMissingPath('data.0.actor_snapshot');
        $this->actingAs($manager)->getJson("/api/compliance-case-intakes/{$intake->id}/messages")->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('data.2.disposition_snapshot.fingerprint', $decision->fingerprint);
        $this->actingAs($other)->getJson("/api/compliance-case-intakes/{$intake->id}/messages")->assertForbidden();
        $this->actingAs($reporter)->postJson("/api/compliance-case-intakes/{$intake->id}/messages", [
            'audience' => ComplianceCaseIntakeAudience::Internal->value, 'message' => 'Reporter cannot create an internal note.',
        ])->assertForbidden();

        Config::set('enterprise.modules.compliance_cases', false);
        $this->actingAs($manager)->getJson("/api/compliance-case-intakes/{$intake->id}/messages")->assertForbidden();
        Config::set('enterprise.modules.compliance_cases', true);

        Livewire::actingAs($manager)->test(MessagesRelationManager::class, [
            'ownerRecord' => $intake, 'pageClass' => ComplianceCaseIntakeResource::class,
        ])->assertCanSeeTableRecords([$internalMessage, $managerMessage, $reporterMessage])
            ->assertSee($internalMessage->message)->assertSee($internalMessage->fingerprint);

        $reporterMessage->message = 'Mutation';
        $this->expectException(\LogicException::class);
        $reporterMessage->save();
    }

    public function test_correspondence_bound_factory_and_retained_migration_are_exact(): void
    {
        $reporter = User::factory()->create();
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $intake = app(ComplianceCaseIntakeManager::class)->submit($reporter, [
            'title' => 'Bounded correspondence', 'category' => ComplianceCaseCategory::Other->value,
            'priority' => ComplianceCasePriority::Medium->value, 'allegation' => 'Bound test concern.',
            'source_channel' => 'Authenticated employee portal',
        ]);
        $messages = app(ComplianceCaseIntakeCorrespondenceManager::class);

        ComplianceCaseIntakeMessage::factory()->count(100)->sequence(
            fn ($sequence): array => ['version' => $sequence->index + 1]
        )->for($intake, 'intake')->for($manager, 'actor')->create();
        $factoryMessage = $intake->messages()->latest('version')->firstOrFail();
        $this->assertSame(ComplianceCaseIntakeAudience::Reporter, $factoryMessage->audience);
        $this->assertSame(hash('sha256', CanonicalJson::encode($messages->payload($factoryMessage))), $factoryMessage->fingerprint);
        try {
            $messages->record($manager, $intake, [
                'audience' => ComplianceCaseIntakeAudience::Internal->value, 'message' => 'The 101st message must fail.',
            ]);
            $this->fail('Expected the exact correspondence bound.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('intake', $exception->errors());
        }
        $this->assertSame(100, $intake->messages()->count());

        $migration = require database_path('migrations/2026_08_25_120000_create_compliance_case_intake_messages.php');
        $migration->up();
        $migration->down();
        $this->assertTrue(Schema::hasTable('compliance_case_intake_messages'));
        $this->assertDatabaseHas('compliance_case_intake_messages', ['id' => $factoryMessage->id, 'fingerprint' => $factoryMessage->fingerprint]);
    }
}
