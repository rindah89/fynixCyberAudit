<?php

namespace Tests\Feature;

use App\ComplianceCases\ComplianceCaseConflictManager;
use App\ComplianceCases\ComplianceCaseManager;
use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCasePriority;
use App\Enums\ComplianceCaseStatus;
use App\Filament\Resources\ComplianceCaseResource\Pages\ViewComplianceCase;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\ConflictsRelationManager;
use App\Models\ComplianceCaseConflictDeclaration;
use App\Models\User;
use App\Support\CanonicalJson;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ComplianceCaseConflictTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.compliance_cases', true);
    }

    public function test_confirmed_conflict_recuses_actor_from_assignment_review_and_closure(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $subject = User::factory()->create();
        $subject->givePermissionTo(['Investigate Compliance Cases', 'Manage Compliance Cases']);
        $reviewer = User::factory()->create();
        $reviewer->assignRole('Security Admin');
        $cases = app(ComplianceCaseManager::class);
        $conflicts = app(ComplianceCaseConflictManager::class);

        $case = $cases->open($manager, [
            'title' => 'Conflicted investigation', 'category' => ComplianceCaseCategory::ConflictOfInterest->value,
            'priority' => ComplianceCasePriority::High->value, 'allegation' => 'A governed allegation.',
            'summary' => 'Open the case.',
        ]);

        $declaration = $this->actingAs($subject)->postJson("/api/compliance-cases/{$case->id}/conflicts", [
            'subject_user_id' => $subject->id,
            'nature' => 'Personal relationship with a named participant.',
            'rationale' => 'The investigator cannot remain independent.',
        ])->assertCreated()->assertJsonMissingPath('data.case_snapshot.allegation');
        $declarationId = $declaration->json('data.id');
        $record = ComplianceCaseConflictDeclaration::query()->findOrFail($declarationId);
        $this->assertSame(hash('sha256', CanonicalJson::encode($conflicts->declarationPayload($record))), $record->fingerprint);

        $this->actingAs($subject)->postJson("/api/compliance-case-conflicts/{$declarationId}/decision", [
            'decision' => 'confirmed', 'summary' => 'Self confirmation is forbidden.',
        ])->assertForbidden();

        $decisionId = $this->actingAs($reviewer)->postJson("/api/compliance-case-conflicts/{$declarationId}/decision", [
            'decision' => 'confirmed', 'summary' => 'The declared relationship recuses the named actor.',
        ])->assertCreated()->json('data.id');
        $record = $record->fresh()->load('decision');
        $this->assertSame('confirmed', $record->decision->decision->value);
        $this->assertSame(hash('sha256', CanonicalJson::encode($conflicts->decisionPayload($record->decision))), $record->decision->fingerprint);

        try {
            $cases->record($manager, $case->refresh(), [
                'status' => ComplianceCaseStatus::Triaged->value, 'assigned_to' => $subject->id,
                'triage_summary' => 'Cannot assign a recused investigator.', 'summary' => 'Blocked assignment.',
            ]);
            $this->fail('Expected a confirmed conflict to block assignment.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $investigator = User::factory()->create();
        $investigator->givePermissionTo('Investigate Compliance Cases');
        $cases->record($manager, $case->refresh(), [
            'status' => ComplianceCaseStatus::Triaged->value, 'assigned_to' => $investigator->id,
            'triage_summary' => 'An unconflicted investigator is assigned.', 'summary' => 'Assign a clear investigator.',
        ]);
        try {
            $cases->record($subject, $case->refresh(), [
                'investigation_summary' => 'Recused mutation.', 'summary' => 'Must fail.',
            ]);
            $this->fail('Expected a recused manager to be excluded from investigation work.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $investigatorDeclaration = $this->actingAs($investigator)->postJson("/api/compliance-cases/{$case->id}/conflicts", [
            'subject_user_id' => $investigator->id,
            'nature' => 'The assigned investigator later became conflicted.',
            'rationale' => 'Recusal must block investigation artifacts.',
        ])->assertCreated()->json('data.id');
        $this->actingAs($reviewer)->postJson("/api/compliance-case-conflicts/{$investigatorDeclaration}/decision", [
            'decision' => 'confirmed', 'summary' => 'The assigned investigator is recused.',
        ])->assertCreated();
        $this->actingAs($investigator)->postJson("/api/compliance-cases/{$case->id}/investigation-plans", [
            'objectives' => ['Facts'], 'scope' => 'Records.', 'procedures' => ['Inspect records'],
            'target_completion_at' => now()->addMonth()->toDateString(), 'rationale' => 'Must fail after recusal.',
        ])->assertForbidden();

        $this->actingAs($manager)->getJson("/api/compliance-cases/{$case->id}/conflicts")
            ->assertOk()->assertJsonPath('data.0.id', $record->id)
            ->assertJsonPath('data.0.decision.id', $decisionId)
            ->assertJsonPath('data.0.subject_user_id', $subject->id);
        Livewire::actingAs($manager)->test(ConflictsRelationManager::class, [
            'ownerRecord' => $case, 'pageClass' => ViewComplianceCase::class,
        ])->assertCanSeeTableRecords([$record]);
    }

    public function test_recused_manager_cannot_decide_another_conflict_through_rest_or_operator(): void
    {
        $opener = User::factory()->create();
        $opener->assignRole('Security Admin');
        $recused = User::factory()->create();
        $recused->assignRole('Security Admin');
        $confirmer = User::factory()->create();
        $confirmer->assignRole('Security Admin');
        $subject = User::factory()->create();
        $subject->givePermissionTo('Investigate Compliance Cases');
        $case = app(ComplianceCaseManager::class)->open($opener, [
            'title' => 'Conflict decide recusal', 'category' => ComplianceCaseCategory::Other->value,
            'priority' => ComplianceCasePriority::Medium->value, 'allegation' => 'A governed allegation.',
            'summary' => 'Open.',
        ]);
        $self = $this->actingAs($recused)->postJson("/api/compliance-cases/{$case->id}/conflicts", [
            'subject_user_id' => $recused->id, 'nature' => 'Self conflict.', 'rationale' => 'Recuse this manager.',
        ])->assertCreated()->json('data.id');
        $this->actingAs($confirmer)->postJson("/api/compliance-case-conflicts/{$self}/decision", [
            'decision' => 'confirmed', 'summary' => 'Manager is recused.',
        ])->assertCreated();
        $pendingId = $this->actingAs($opener)->postJson("/api/compliance-cases/{$case->id}/conflicts", [
            'subject_user_id' => $subject->id, 'nature' => 'Separate conflict.', 'rationale' => 'Pending decision.',
        ])->assertCreated()->json('data.id');
        $pending = ComplianceCaseConflictDeclaration::query()->findOrFail($pendingId);
        $this->actingAs($recused)->postJson("/api/compliance-case-conflicts/{$pendingId}/decision", [
            'decision' => 'confirmed', 'summary' => 'Recused manager must not decide.',
        ])->assertForbidden();
        try {
            Livewire::actingAs($recused)->test(ConflictsRelationManager::class, [
                'ownerRecord' => $case, 'pageClass' => ViewComplianceCase::class,
            ])->callTableAction('decide', $pending, [
                'decision' => 'confirmed', 'summary' => 'Operator recusal must match REST.',
            ]);
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertFalse($pending->fresh()->decision()->exists());
    }

    public function test_conflict_bounds_immutability_factory_and_module_flag_are_governed(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $reviewer = User::factory()->create();
        $reviewer->assignRole('Security Admin');
        $cases = app(ComplianceCaseManager::class);
        $conflicts = app(ComplianceCaseConflictManager::class);
        $case = $cases->open($manager, [
            'title' => 'Bound conflicts', 'category' => ComplianceCaseCategory::Other->value,
            'priority' => ComplianceCasePriority::Medium->value, 'allegation' => 'Bound test.',
            'summary' => 'Open bound test.',
        ]);
        $factory = ComplianceCaseConflictDeclaration::factory()->create();
        $this->assertSame(hash('sha256', CanonicalJson::encode($conflicts->declarationPayload($factory))), $factory->fingerprint);
        $this->assertThrows(fn () => $factory->update(['nature' => 'Rewrite']), \LogicException::class);
        foreach (range(1, 20) as $version) {
            $subject = User::factory()->create();
            $declaration = $conflicts->declare($manager, $case->refresh(), [
                'subject_user_id' => $subject->id, 'nature' => "Declaration {$version}.",
                'rationale' => "Independence concern {$version}.",
            ]);
            $conflicts->decide($reviewer, $declaration, ['decision' => 'rejected', 'summary' => "Reject {$version}."]);
        }
        $this->actingAs($manager)->postJson("/api/compliance-cases/{$case->id}/conflicts", [
            'subject_user_id' => User::factory()->create()->id, 'nature' => 'Overflow.', 'rationale' => 'Must fail.',
        ])->assertUnprocessable();
        Config::set('enterprise.modules.compliance_cases', false);
        $this->actingAs($manager)->postJson("/api/compliance-cases/{$case->id}/conflicts", [
            'subject_user_id' => User::factory()->create()->id, 'nature' => 'Disabled.', 'rationale' => 'Disabled.',
        ])->assertForbidden();
        $migration = require database_path('migrations/2026_08_25_200000_create_compliance_case_conflicts.php');
        $migration->up();
        $migration->down();
        $this->assertTrue(Schema::hasTable('compliance_case_conflict_declarations'));
    }
}
