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
use App\Models\ComplianceCaseInvestigationProcedureReview;
use App\Models\User;
use App\Support\CanonicalJson;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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
        $executions->review($reviewer, $first, ['decision' => 'approved', 'summary' => 'Interview conclusion approved.']);

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
        $secondResponse = $this->actingAs($investigator)->postJson("/api/compliance-cases/{$case->id}/investigation-procedure-executions", [
            'procedure_index' => 2, 'result' => ComplianceCaseInvestigationProcedureResult::ExceptionIdentified->value,
            'summary' => 'Approval record inspected.', 'findings' => 'One approval step lacked attribution.',
        ])->assertCreated()->assertJsonPath('data.procedure_text', 'Inspect the approval record');
        $executions->review($reviewer, ComplianceCaseInvestigationProcedureExecution::findOrFail($secondResponse->json('data.id')), [
            'decision' => 'approved', 'summary' => 'Approval-record conclusion approved.',
        ]);
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

    public function test_latest_procedure_conclusion_requires_independent_approval_before_resolution(): void
    {
        $first = ComplianceCaseInvestigationProcedureExecution::factory()->create()->fresh();
        $reviewer = User::factory()->create();
        $reviewer->assignRole('Security Admin');
        $managerExecutor = User::factory()->create();
        $managerExecutor->assignRole('Security Admin');
        $cases = app(ComplianceCaseManager::class);

        $this->actingAs($first->executor)->postJson("/api/compliance-case-investigation-procedure-executions/{$first->id}/review", [
            'decision' => 'approved', 'summary' => 'Self review is forbidden.',
        ])->assertForbidden();

        $firstReviewResponse = $this->actingAs($reviewer)->postJson("/api/compliance-case-investigation-procedure-executions/{$first->id}/review", [
            'decision' => 'rework_required', 'summary' => 'Clarify the retained conclusion.',
        ])->assertCreated()->assertJsonPath('data.decision', 'rework_required');
        $service = app(ComplianceCaseInvestigationProcedureExecutionManager::class);
        $firstReview = ComplianceCaseInvestigationProcedureReview::findOrFail($firstReviewResponse->json('data.id'));
        $this->assertSame(hash('sha256', CanonicalJson::encode($service->reviewPayload($firstReview))), $firstReview->fingerprint);
        $this->assertSame($first->fingerprint, data_get($firstReview->execution_snapshot, 'fingerprint'));

        $second = $this->actingAs($managerExecutor)->postJson("/api/compliance-cases/{$first->compliance_case_id}/investigation-procedure-executions", [
            'procedure_index' => $first->procedure_index, 'result' => ComplianceCaseInvestigationProcedureResult::Completed->value,
            'summary' => 'Revised conclusion with the requested clarification.', 'findings' => 'The clarified facts are retained.',
        ])->assertCreated()->assertJsonPath('data.version', 2)->json('data');

        try {
            $cases->record($first->executor, $first->complianceCase->refresh(), [
                'status' => ComplianceCaseStatus::Resolved->value, 'resolution_summary' => 'Premature.', 'summary' => 'Resolve.',
            ]);
            $this->fail('Expected the latest procedure conclusion to require supervisory approval.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('investigation_procedures', $exception->errors());
        }

        $this->actingAs($reviewer)->postJson("/api/compliance-case-investigation-procedure-executions/{$second['id']}/review", [
            'decision' => 'approved', 'summary' => 'The revised conclusion is approved.',
        ])->assertCreated()->assertJsonPath('data.decision', 'approved');

        $cases->record($first->executor, $first->complianceCase->refresh(), [
            'status' => ComplianceCaseStatus::Resolved->value,
            'resolution_summary' => 'Every latest procedure conclusion received independent approval.', 'summary' => 'Resolve.',
        ]);
        $this->assertSame(ComplianceCaseStatus::Resolved, $first->complianceCase->refresh()->status);

        foreach ([$managerExecutor, $reviewer] as $excludedCloser) {
            try {
                $cases->record($excludedCloser, $first->complianceCase->refresh(), [
                    'status' => ComplianceCaseStatus::Closed->value, 'closure_summary' => 'Not independent.', 'summary' => 'Close.',
                ]);
                $this->fail('Expected procedure execution and review actors to be excluded from final closure.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        try {
            $firstReview->summary = 'Mutation';
            $firstReview->save();
            $this->fail('Expected supervisory review immutability.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('compliance_case_investigation_procedure_reviews', ['id' => $firstReview->id, 'fingerprint' => $firstReview->fingerprint]);
        }
        try {
            $firstReview->delete();
            $this->fail('Expected retained supervisory review evidence.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('compliance_case_investigation_procedure_reviews', ['id' => $firstReview->id]);
        }
    }

    public function test_procedure_rework_history_is_bounded_to_twenty_versions(): void
    {
        $execution = ComplianceCaseInvestigationProcedureExecution::factory()->create()->fresh();
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('Manage Compliance Cases');
        $service = app(ComplianceCaseInvestigationProcedureExecutionManager::class);

        while ($execution->version < 20) {
            $service->review($reviewer, $execution, ['decision' => 'rework_required', 'summary' => "Rework version {$execution->version}."]);
            $execution = $service->record($execution->executor, $execution->complianceCase, [
                'procedure_index' => $execution->procedure_index, 'result' => ComplianceCaseInvestigationProcedureResult::Completed->value,
                'summary' => 'Revised bounded conclusion.', 'findings' => 'Retained bounded findings.',
            ])->fresh();
        }
        $service->review($reviewer, $execution, ['decision' => 'rework_required', 'summary' => 'Final bounded rework decision.']);
        $this->assertSame(20, $execution->version);
        $this->assertSame(20, $execution->complianceCase->investigationProcedureExecutions()->count());

        try {
            $service->record($execution->executor, $execution->complianceCase, [
                'procedure_index' => $execution->procedure_index, 'result' => ComplianceCaseInvestigationProcedureResult::Completed->value,
                'summary' => 'Overflow.',
            ]);
            $this->fail('Expected the exact 20-version bound.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('procedure_index', $exception->errors());
        }
        $this->assertSame(20, $execution->complianceCase->investigationProcedureExecutions()->count());
    }

    public function test_execution_authorization_factory_operator_and_retained_migration_are_governed(): void
    {
        $execution = ComplianceCaseInvestigationProcedureExecution::factory()->create()->fresh();
        $review = ComplianceCaseInvestigationProcedureReview::factory()->for($execution, 'execution')->create()->fresh();
        $service = app(ComplianceCaseInvestigationProcedureExecutionManager::class);
        $this->assertSame(ComplianceCaseStatus::Investigating, $execution->complianceCase->status);
        $this->assertSame(ComplianceCaseInvestigationPlanDecision::Approved, $execution->plan->review->decision);
        $this->assertSame(hash('sha256', CanonicalJson::encode($service->payload($execution))), $execution->fingerprint);
        $this->assertSame($execution->plan->review->fingerprint, data_get($execution->plan_snapshot, 'review.fingerprint'));
        $this->assertSame(hash('sha256', CanonicalJson::encode($service->reviewPayload($review))), $review->fingerprint);

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
            ->assertJsonPath('data.0.plan_snapshot.review.fingerprint', $execution->plan->review->fingerprint)
            ->assertJsonPath('data.0.review.fingerprint', $review->fingerprint);
        $this->actingAs($outsider)->getJson("/api/compliance-cases/{$execution->compliance_case_id}/investigation-procedure-executions")->assertForbidden();
        Livewire::actingAs($manager)->test(InvestigationProcedureExecutionsRelationManager::class, [
            'ownerRecord' => $execution->complianceCase, 'pageClass' => ViewComplianceCase::class,
        ])->assertCanSeeTableRecords([$execution])->mountTableAction('inspect', $execution);
        $operatorEvidence = view('filament.compliance-case-investigation-procedure-execution', ['execution' => $execution->load(['executor', 'plan.review', 'review.reviewer'])])->render();
        $this->assertStringContainsString($execution->summary, $operatorEvidence);
        $this->assertStringContainsString($execution->plan->review->fingerprint, $operatorEvidence);
        $this->assertStringContainsString($review->summary, $operatorEvidence);
        $this->assertStringContainsString($review->fingerprint, $operatorEvidence);

        $legacy = ComplianceCaseInvestigationProcedureExecution::factory()->create()->fresh();
        $legacy->forceFill(['fingerprint_version' => 'procedure-execution/v1', 'version' => 1]);
        $legacyFingerprint = hash('sha256', CanonicalJson::encode($service->payload($legacy)));
        DB::table('compliance_case_investigation_procedure_executions')->where('id', $legacy->id)->update([
            'fingerprint_version' => 'procedure-execution/v1', 'fingerprint' => $legacyFingerprint,
        ]);
        $this->assertArrayNotHasKey('version', $service->payload($legacy));

        $migration = require database_path('migrations/2026_08_25_160000_create_compliance_case_investigation_procedure_reviews.php');
        $migration->up();
        $migration->down();
        $this->assertTrue(Schema::hasTable('compliance_case_investigation_procedure_executions'));
        $this->assertTrue(Schema::hasTable('compliance_case_investigation_procedure_reviews'));
        $this->assertDatabaseHas('compliance_case_investigation_procedure_executions', ['id' => $execution->id, 'fingerprint' => $execution->fingerprint]);
        $this->assertDatabaseHas('compliance_case_investigation_procedure_reviews', ['id' => $review->id, 'fingerprint' => $review->fingerprint]);
        $legacy->refresh();
        $this->assertSame($legacyFingerprint, hash('sha256', CanonicalJson::encode($service->payload($legacy))));
    }
}
