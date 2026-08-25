<?php

namespace Tests\Feature;

use App\ComplianceCases\ComplianceCaseInvestigationReportManager;
use App\ComplianceCases\ComplianceCaseManager;
use App\Enums\ComplianceCaseStatus;
use App\Filament\Resources\ComplianceCaseResource\Pages\ViewComplianceCase;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\InvestigationReportsRelationManager;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseInvestigationProcedureExecution;
use App\Models\ComplianceCaseInvestigationProcedureReview;
use App\Models\ComplianceCaseInvestigationReport;
use App\Models\ComplianceCaseInvestigationReportReview;
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

class ComplianceCaseInvestigationReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.compliance_cases', true);
    }

    public function test_current_investigation_report_requires_independent_approval_before_resolution(): void
    {
        $execution = ComplianceCaseInvestigationProcedureExecution::factory()->create()->fresh();
        ComplianceCaseInvestigationProcedureReview::factory()->for($execution, 'execution')->create();
        $case = $execution->complianceCase;
        $author = $execution->executor;
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('Manage Compliance Cases');

        $reportData = $this->actingAs($author)->postJson("/api/compliance-cases/{$case->id}/investigation-reports", [
            'executive_summary' => 'The governed investigation addressed the approved scope.',
            'analysis' => 'The retained conclusions were compared with the allegation and governed evidence history.',
            'findings' => 'The approval process lacked attributable evidence for one required step.',
            'recommendations' => 'Require attributable approval evidence before processing.',
            'outcome' => 'substantiated',
        ])->assertCreated()->assertJsonPath('data.version', 1)->json('data');
        $report = ComplianceCaseInvestigationReport::findOrFail($reportData['id'])->fresh();
        $service = app(ComplianceCaseInvestigationReportManager::class);
        $this->assertSame(hash('sha256', CanonicalJson::encode($service->reportPayload($report))), $report->fingerprint);
        $this->assertSame($execution->fingerprint, data_get($report->report_snapshot, 'source_fingerprints.procedure_conclusions.0'));
        $this->assertSame($execution->review->fingerprint, data_get($report->report_snapshot, 'source_fingerprints.procedure_reviews.0'));
        $this->assertSame($execution->plan_snapshot, data_get($report->report_snapshot, 'procedure_conclusions.0.plan_snapshot'));
        $this->assertSame($execution->case_snapshot, data_get($report->report_snapshot, 'procedure_conclusions.0.case_snapshot'));
        $this->assertSame($execution->review->execution_snapshot, data_get($report->report_snapshot, 'procedure_conclusions.0.supervisory_review.execution_snapshot'));

        try {
            app(ComplianceCaseManager::class)->record($author, $case->refresh(), [
                'status' => ComplianceCaseStatus::Resolved->value, 'resolution_summary' => 'Premature.', 'summary' => 'Resolve.',
            ]);
            $this->fail('Expected an independently approved current investigation report before resolution.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('investigation_report', $exception->errors());
        }

        $this->actingAs($author)->postJson("/api/compliance-case-investigation-reports/{$report->id}/review", [
            'decision' => 'approved', 'summary' => 'Self review is forbidden.',
        ])->assertForbidden();
        $reviewData = $this->actingAs($reviewer)->postJson("/api/compliance-case-investigation-reports/{$report->id}/review", [
            'decision' => 'approved', 'summary' => 'The report fairly synthesizes the retained investigation record.',
        ])->assertCreated()->assertJsonPath('data.decision', 'approved')->json('data');
        $review = ComplianceCaseInvestigationReportReview::findOrFail($reviewData['id'])->fresh();
        $this->assertSame(hash('sha256', CanonicalJson::encode($service->reviewPayload($review))), $review->fingerprint);
        $this->assertSame($report->fingerprint, data_get($review->report_snapshot, 'fingerprint'));

        app(ComplianceCaseManager::class)->record($author, $case->refresh(), [
            'status' => ComplianceCaseStatus::Resolved->value,
            'resolution_summary' => 'The independently approved report supports resolution.', 'summary' => 'Resolve.',
        ]);
        $this->assertSame(ComplianceCaseStatus::Resolved, $case->refresh()->status);
    }

    public function test_report_authorization_staleness_interfaces_factories_and_retention_are_governed(): void
    {
        $execution = ComplianceCaseInvestigationProcedureExecution::factory()->create()->fresh();
        $procedureReview = ComplianceCaseInvestigationProcedureReview::factory()->for($execution, 'execution')->create();
        $case = $execution->complianceCase;
        $service = app(ComplianceCaseInvestigationReportManager::class);
        $outsider = User::factory()->create();
        try {
            $service->submit($outsider, $case, ['outcome' => 'invalid']);
            $this->fail('Expected current case authorization before report payload validation.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $report = $service->submit($execution->executor, $case, [
            'outcome' => 'inconclusive', 'executive_summary' => 'Current report.', 'analysis' => 'Current analysis.',
            'findings' => 'Current findings.', 'recommendations' => 'Current recommendations.',
        ]);
        try {
            $service->review($procedureReview->reviewer, $report, ['decision' => 'approved', 'summary' => 'Conflicted.']);
            $this->fail('Expected a procedure reviewer to be excluded from final-report review.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $manager = User::factory()->create();
        $manager->givePermissionTo('Manage Compliance Cases');
        app(ComplianceCaseManager::class)->record($manager, $case, [
            'investigation_summary' => 'A later material case event makes the report stale.', 'summary' => 'Retain later context.',
        ]);
        try {
            $service->review($manager, $report, ['decision' => 'approved', 'summary' => 'Stale approval.']);
            $this->fail('Expected stale report approval to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('report', $exception->errors());
        }
        $rejection = $service->review($manager, $report, ['decision' => 'rejected', 'summary' => 'Replace the stale report.']);
        $replacement = $service->submit($execution->executor, $case->refresh(), [
            'outcome' => 'substantiated', 'executive_summary' => 'Replacement report.', 'analysis' => 'Replacement analysis.',
            'findings' => 'Replacement findings.', 'recommendations' => 'Replacement recommendations.',
        ]);
        $approver = User::factory()->create();
        $approver->givePermissionTo('Manage Compliance Cases');
        $approval = $service->review($approver, $replacement, ['decision' => 'approved', 'summary' => 'Approved replacement.']);

        for ($version = 3; $version <= 20; $version++) {
            app(ComplianceCaseManager::class)->record($manager, $case->refresh(), [
                'investigation_summary' => "Material report context version {$version}.", 'summary' => 'Advance retained report context.',
            ]);
            $boundedReport = $service->submit($execution->executor, $case->refresh(), [
                'outcome' => 'inconclusive', 'executive_summary' => "Bounded report {$version}.",
                'analysis' => 'Bounded analysis.', 'findings' => 'Bounded findings.', 'recommendations' => 'Bounded recommendations.',
            ]);
            $service->review($manager, $boundedReport, ['decision' => 'rejected', 'summary' => 'Retain the bounded report version.']);
        }
        try {
            $service->submit($execution->executor, $case->refresh(), [
                'outcome' => 'inconclusive', 'executive_summary' => 'Overflow report.',
                'analysis' => 'Overflow analysis.', 'findings' => 'Overflow findings.', 'recommendations' => 'Overflow recommendations.',
            ]);
            $this->fail('Expected the exact 20-report bound.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('case', $exception->errors());
        }

        $this->actingAs($manager)->getJson("/api/compliance-cases/{$case->id}/investigation-reports?per_page=1")
            ->assertOk()->assertJsonPath('total', 20)->assertJsonPath('data.0.review.fingerprint', $rejection->fingerprint);
        $this->actingAs($outsider)->getJson("/api/compliance-cases/{$case->id}/investigation-reports")->assertForbidden();
        Livewire::actingAs($manager)->test(InvestigationReportsRelationManager::class, [
            'ownerRecord' => $case, 'pageClass' => ViewComplianceCase::class,
        ])->assertCanSeeTableRecords([$report, $replacement])->mountTableAction('inspect', $replacement);
        $rendered = view('filament.compliance-case-investigation-report', [
            'report' => $replacement->fresh()->load(['author', 'review.reviewer']),
        ])->render();
        $this->assertStringContainsString('Replacement analysis.', $rendered);
        $this->assertStringContainsString($approval->fingerprint, $rendered);

        $factoryReview = ComplianceCaseInvestigationReportReview::factory()->create()->fresh();
        $factoryService = app(ComplianceCaseInvestigationReportManager::class);
        $this->assertSame(hash('sha256', CanonicalJson::encode($factoryService->reportPayload($factoryReview->report->fresh()))), $factoryReview->report->fingerprint);
        $this->assertSame(hash('sha256', CanonicalJson::encode($factoryService->reviewPayload($factoryReview))), $factoryReview->fingerprint);
        foreach ([$replacement, $approval] as $immutable) {
            try {
                $immutable->delete();
                $this->fail('Expected report evidence retention.');
            } catch (\LogicException) {
                $this->assertDatabaseHas($immutable->getTable(), ['id' => $immutable->id, 'fingerprint' => $immutable->fingerprint]);
            }
        }

        $legacy = ComplianceCase::factory()->create(['investigation_reporting_governed_at' => null]);
        $this->actingAs($manager)->getJson("/api/compliance-cases/{$legacy->id}")->assertOk()
            ->assertJsonPath('data.investigation_reporting_governance_status', 'legacy');
        $migration = require database_path('migrations/2026_08_25_170000_create_compliance_case_investigation_reports.php');
        $migration->up();
        $migration->down();
        $this->assertTrue(Schema::hasTable('compliance_case_investigation_reports'));
        $this->assertTrue(Schema::hasTable('compliance_case_investigation_report_reviews'));
        $this->assertDatabaseHas('compliance_case_investigation_reports', ['id' => $replacement->id]);
        $this->assertDatabaseHas('compliance_case_investigation_report_reviews', ['id' => $approval->id]);
    }
}
