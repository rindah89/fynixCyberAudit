<?php

namespace Tests\Feature;

use App\ComplianceCases\ComplianceCaseInvestigationPlanManager;
use App\ComplianceCases\ComplianceCaseInvestigationProcedureExecutionManager;
use App\ComplianceCases\ComplianceCaseManager;
use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCaseInvestigationPlanDecision;
use App\Enums\ComplianceCaseInvestigationProcedureResult;
use App\Enums\ComplianceCasePriority;
use App\Enums\ComplianceCaseStatus;
use App\Filament\Resources\ComplianceCaseResource\Pages\ViewComplianceCase;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\InvestigationProcedureExecutionsRelationManager;
use App\Models\ComplianceCaseInvestigationProcedureExecution;
use App\Models\User;
use App\Support\CanonicalJson;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ComplianceCaseInvestigationProcedureExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.compliance_cases', true);
    }

    public function test_every_approved_plan_procedure_requires_immutable_execution_before_resolution(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $investigator = User::factory()->create();
        $investigator->givePermissionTo('Investigate Compliance Cases');
        $reviewer = User::factory()->create();
        $reviewer->assignRole('Security Admin');
        $cases = app(ComplianceCaseManager::class);
        $plans = app(ComplianceCaseInvestigationPlanManager::class);
        $executions = app(ComplianceCaseInvestigationProcedureExecutionManager::class);
        $case = $cases->open($manager, [
            'title' => 'Procedure-governed investigation', 'category' => ComplianceCaseCategory::Other->value,
            'priority' => ComplianceCasePriority::High->value, 'allegation' => 'A governed allegation.', 'summary' => 'Open.',
        ]);
        $cases->record($manager, $case, [
            'status' => ComplianceCaseStatus::Triaged->value, 'assigned_to' => $investigator->id,
            'triage_summary' => 'Investigation required.', 'summary' => 'Triage.',
        ]);
        $plan = $plans->submit($investigator, $case->refresh(), [
            'objectives' => ['Establish the facts'], 'scope' => 'Relevant conduct and records.',
            'procedures' => ['Interview the reporter', 'Inspect the approval record'],
            'target_completion_at' => now()->addDays(14)->toDateString(), 'rationale' => 'Bound the work.',
        ]);
        $plans->review($reviewer, $plan, [
            'decision' => ComplianceCaseInvestigationPlanDecision::Approved->value, 'summary' => 'Approved.',
        ]);
        $cases->record($investigator, $case->refresh(), [
            'status' => ComplianceCaseStatus::Investigating->value, 'investigation_summary' => 'Work started.', 'summary' => 'Start.',
        ]);

        $first = $executions->record($investigator, $case->refresh(), [
            'procedure_index' => 1, 'result' => ComplianceCaseInvestigationProcedureResult::Completed->value,
            'summary' => 'Interview completed.', 'findings' => 'The reporter confirmed the submitted chronology.',
            'source_reference' => 'Interview note INT-001',
        ])->fresh();
        $this->assertSame('Interview the reporter', $first->procedure_text);
        $this->assertSame(hash('sha256', CanonicalJson::encode($executions->payload($first))), $first->fingerprint);
        $this->assertSame($plan->fingerprint, data_get($first->plan_snapshot, 'fingerprint'));

        try {
            $cases->record($investigator, $case->refresh(), [
                'status' => ComplianceCaseStatus::Resolved->value, 'resolution_summary' => 'Premature.', 'summary' => 'Resolve.',
            ]);
            $this->fail('Expected every approved-plan procedure to require execution.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('investigation_procedures', $exception->errors());
        }

        $this->actingAs($investigator)->postJson("/api/compliance-cases/{$case->id}/investigation-procedure-executions", [
            'procedure_index' => 2, 'result' => ComplianceCaseInvestigationProcedureResult::ExceptionIdentified->value,
            'summary' => 'Invalid server-owned evidence.', 'findings' => 'Invalid.', 'fingerprint' => str_repeat('a', 64),
        ])->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $this->actingAs($investigator)->postJson("/api/compliance-cases/{$case->id}/investigation-procedure-executions", [
            'procedure_index' => 2, 'result' => ComplianceCaseInvestigationProcedureResult::ExceptionIdentified->value,
            'summary' => 'Missing retained findings.',
        ])->assertUnprocessable()->assertJsonValidationErrors('findings');
        $this->actingAs($investigator)->postJson("/api/compliance-cases/{$case->id}/investigation-procedure-executions", [
            'procedure_index' => 2, 'result' => ComplianceCaseInvestigationProcedureResult::ExceptionIdentified->value,
            'summary' => 'Approval record inspected.', 'findings' => 'One approval step lacked attribution.',
        ])->assertCreated()->assertJsonPath('data.procedure_text', 'Inspect the approval record');
        $cases->record($investigator, $case->refresh(), [
            'status' => ComplianceCaseStatus::Resolved->value, 'resolution_summary' => 'Every planned procedure has a retained conclusion.',
            'summary' => 'Resolve after completing the approved plan.',
        ]);
        $this->assertSame(ComplianceCaseStatus::Resolved, $case->refresh()->status);
        $this->assertCount(2, $case->investigationProcedureExecutions);

        try {
            $first->summary = 'Mutation';
            $first->save();
            $this->fail('Expected execution immutability.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('compliance_case_investigation_procedure_executions', ['id' => $first->id, 'fingerprint' => $first->fingerprint]);
        }
        try {
            $first->delete();
            $this->fail('Expected retained execution evidence.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('compliance_case_investigation_procedure_executions', ['id' => $first->id]);
        }
    }

    public function test_execution_authorization_factory_operator_and_retained_migration_are_governed(): void
    {
        $execution = ComplianceCaseInvestigationProcedureExecution::factory()->create()->fresh();
        $service = app(ComplianceCaseInvestigationProcedureExecutionManager::class);
        $this->assertSame(ComplianceCaseStatus::Investigating, $execution->complianceCase->status);
        $this->assertSame(ComplianceCaseInvestigationPlanDecision::Approved, $execution->plan->review->decision);
        $this->assertSame(hash('sha256', CanonicalJson::encode($service->payload($execution))), $execution->fingerprint);
        $this->assertSame($execution->plan->review->fingerprint, data_get($execution->plan_snapshot, 'review.fingerprint'));

        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $outsider = User::factory()->create();
        try {
            $service->record($outsider, $execution->complianceCase, ['procedure_index' => PHP_INT_MAX]);
            $this->fail('Expected current case authorization before payload validation.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        try {
            $service->record($execution->executor, $execution->complianceCase, [
                'procedure_index' => $execution->procedure_index, 'result' => ComplianceCaseInvestigationProcedureResult::Completed->value,
                'summary' => 'Duplicate.',
            ]);
            $this->fail('Expected one execution per approved-plan procedure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('procedure_index', $exception->errors());
        }

        $this->actingAs($manager)->getJson("/api/compliance-cases/{$execution->compliance_case_id}/investigation-procedure-executions")
            ->assertOk()->assertJsonPath('data.0.fingerprint', $execution->fingerprint)
            ->assertJsonPath('data.0.plan_snapshot.review.fingerprint', $execution->plan->review->fingerprint);
        $this->actingAs($outsider)->getJson("/api/compliance-cases/{$execution->compliance_case_id}/investigation-procedure-executions")->assertForbidden();
        Livewire::actingAs($manager)->test(InvestigationProcedureExecutionsRelationManager::class, [
            'ownerRecord' => $execution->complianceCase, 'pageClass' => ViewComplianceCase::class,
        ])->assertCanSeeTableRecords([$execution])->mountTableAction('inspect', $execution);
        $operatorEvidence = view('filament.compliance-case-investigation-procedure-execution', ['execution' => $execution->load(['executor', 'plan.review'])])->render();
        $this->assertStringContainsString($execution->summary, $operatorEvidence);
        $this->assertStringContainsString($execution->plan->review->fingerprint, $operatorEvidence);

        $migration = require database_path('migrations/2026_08_25_150000_create_compliance_case_investigation_procedure_executions.php');
        $migration->up();
        $migration->down();
        $this->assertTrue(Schema::hasTable('compliance_case_investigation_procedure_executions'));
        $this->assertDatabaseHas('compliance_case_investigation_procedure_executions', ['id' => $execution->id, 'fingerprint' => $execution->fingerprint]);
    }
}
