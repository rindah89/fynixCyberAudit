<?php

namespace Tests\Feature;

use App\ComplianceCases\ComplianceCaseCommunicationManager;
use App\ComplianceCases\ComplianceCaseManager;
use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCasePriority;
use App\Models\ComplianceCaseCommunicationDecision;
use App\Models\User;
use App\Support\CanonicalJson;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ComplianceCaseCommunicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.compliance_cases', true);
    }

    public function test_communication_decisions_require_external_reference_only_when_sent(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $case = app(ComplianceCaseManager::class)->open($manager, [
            'title' => 'Communication case', 'category' => ComplianceCaseCategory::Other->value,
            'priority' => ComplianceCasePriority::Medium->value, 'allegation' => 'A governed allegation.',
            'summary' => 'Open.',
        ]);

        $this->actingAs($manager)->postJson("/api/compliance-cases/{$case->id}/communications", [
            'audience' => 'internal', 'purpose' => 'Notify the control owner.',
            'decision' => 'sent', 'deadline_at' => now()->addDay()->toIso8601String(),
        ])->assertUnprocessable();

        $this->actingAs($manager)->postJson("/api/compliance-cases/{$case->id}/communications", [
            'audience' => 'internal', 'purpose' => 'Notify the control owner.',
            'decision' => 'sent', 'deadline_at' => now()->addDay()->toIso8601String(),
            'external_reference' => 'TICKET-441', 'fingerprint' => str_repeat('a', 64),
        ])->assertUnprocessable();

        $id = $this->actingAs($manager)->postJson("/api/compliance-cases/{$case->id}/communications", [
            'audience' => 'internal', 'purpose' => 'Notify the control owner.',
            'decision' => 'sent', 'deadline_at' => now()->addDay()->toIso8601String(),
            'external_reference' => 'TICKET-441',
        ])->assertCreated()->json('data.id');
        $record = ComplianceCaseCommunicationDecision::query()->findOrFail($id);
        $this->assertSame('sent', $record->decision->value);
        $this->assertSame(
            hash('sha256', CanonicalJson::encode(app(ComplianceCaseCommunicationManager::class)->payload($record))),
            $record->fingerprint,
        );
        $this->actingAs($manager)->getJson("/api/compliance-cases/{$case->id}/communications")
            ->assertOk()->assertJsonPath('data.0.id', $id);
        $this->assertThrows(fn () => $record->update(['purpose' => 'Rewrite']), \LogicException::class);
    }

    public function test_communication_factory_reconstructs_production_fingerprint(): void
    {
        $record = ComplianceCaseCommunicationDecision::factory()->create();
        $this->assertSame(
            hash('sha256', CanonicalJson::encode(app(ComplianceCaseCommunicationManager::class)->payload($record))),
            $record->fingerprint,
        );
    }
}
