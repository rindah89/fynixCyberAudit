<?php

namespace Tests\Feature;

use App\Access\FileAccess;
use App\ComplianceCases\ComplianceCaseClosureReportManager;
use App\ComplianceCases\ComplianceCaseClosureReportReviewManager;
use App\ComplianceCases\ComplianceCaseManager;
use App\Enums\ComplianceCaseClosureReportReviewDecision;
use App\Enums\ComplianceCaseInvestigationReportDecision;
use App\Enums\ComplianceCaseStatus;
use App\Filament\Resources\ComplianceCaseResource\Pages\ViewComplianceCase;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\ClosureReportsRelationManager;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseClosureReport;
use App\Models\ComplianceCaseClosureReportReview;
use App\Models\ComplianceCaseInvestigationReport;
use App\Models\ComplianceCaseInvestigationReportReview;
use App\Models\User;
use App\Support\CanonicalJson;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ComplianceCaseClosureReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.compliance_cases', true);
        Storage::fake('private');
    }

    public function test_manager_generates_and_downloads_verified_retained_closure_report(): void
    {
        [$case, $investigationApproval] = $this->closedGovernedCase();

        $generator = User::factory()->create();
        $generator->givePermissionTo(['Manage Compliance Cases', 'Read Compliance Cases']);
        $reportId = $this->actingAs($generator)->postJson("/api/compliance-cases/{$case->id}/closure-reports", [
            'executive_summary' => 'The governed case reached independently approved investigation, resolution, and closure.',
        ])->assertCreated()->assertJsonMissingPath('data.report_snapshot')->assertJsonMissingPath('data.report_path')->json('data.id');

        $report = $case->closureReports()->findOrFail($reportId);
        $service = app(ComplianceCaseClosureReportManager::class);
        $this->assertSame(hash('sha256', CanonicalJson::encode($service->payload($report))), $report->fingerprint);
        $this->assertSame($investigationApproval->fingerprint, data_get($report->report_snapshot, 'approved_investigation_report.review.fingerprint'));
        $this->assertSame('Closed', data_get($report->report_snapshot, 'case.case.status'));
        $this->assertSame($case->events()->reorder()->latest('version')->value('fingerprint'), collect(data_get($report->report_snapshot, 'source_fingerprints.case_events'))->last());
        Storage::disk('private')->assertExists($report->report_path);
        $download = $this->actingAs($generator)->get(route('compliance-case-closure-reports.download', $report))->assertOk();
        $this->assertSame($report->report_sha256, hash('sha256', $download->streamedContent()));

        $reader = User::factory()->create();
        $reader->givePermissionTo('Read Compliance Cases');
        $this->actingAs($reader)->getJson("/api/compliance-cases/{$case->id}/closure-reports")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonMissingPath('data.0.report_snapshot')->assertJsonMissingPath('data.0.report_path');
        $this->actingAs($reader)->get(route('compliance-case-closure-reports.download', $report))->assertOk();

        Livewire::actingAs($generator)->test(ClosureReportsRelationManager::class, [
            'ownerRecord' => $case, 'pageClass' => ViewComplianceCase::class,
        ])->assertCanSeeTableRecords([$report])->mountTableAction('inspect', $report);
        $rendered = view('filament.compliance-case-closure-report', ['report' => $report->load('generator')])->render();
        $this->assertStringContainsString('The governed case reached independently approved investigation', $rendered);
        $this->assertStringContainsString($report->report_sha256, $rendered);

        Storage::disk('private')->put($report->report_path, 'tampered closure report');
        $this->actingAs($generator)->get(route('compliance-case-closure-reports.download', $report))->assertStatus(409);
        Storage::disk('private')->delete($report->report_path);
        $this->actingAs($generator)->get(route('compliance-case-closure-reports.download', $report))->assertNotFound();
        try {
            $report->delete();
            $this->fail('Expected retained closure-report evidence to be immutable.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('compliance_case_closure_reports', ['id' => $report->id, 'fingerprint' => $report->fingerprint]);
        }
    }

    public function test_closure_report_bytes_are_authorized_through_file_access(): void
    {
        $report = ComplianceCaseClosureReport::factory()->create();
        $reader = User::factory()->create();
        $reader->givePermissionTo('Read Compliance Cases');
        $outsider = User::factory()->create();

        $this->assertTrue($reader->can('view', $report));
        $this->assertFalse($outsider->can('view', $report));
        $this->actingAs($outsider)->get('/app/priv-storage/'.$report->report_path)->assertForbidden();
        $this->actingAs($reader)->get('/app/priv-storage/'.$report->report_path)->assertOk();
        try {
            app(FileAccess::class)->streamComplianceCaseClosureReport($outsider, $report);
            $this->fail('Expected unauthorized FileAccess closure-report streaming to fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $stream = app(FileAccess::class)->streamComplianceCaseClosureReport($reader, $report);
        $this->assertSame(200, $stream->getStatusCode());
        ob_start();
        $stream->sendContent();
        $this->assertSame($report->report_sha256, hash('sha256', (string) ob_get_clean()));
    }

    public function test_closure_report_authorization_bounds_factory_and_retained_migration_are_governed(): void
    {
        [$case] = $this->closedGovernedCase();
        $service = app(ComplianceCaseClosureReportManager::class);
        $outsider = User::factory()->create();
        try {
            $service->generate($outsider, $case, ['executive_summary' => '']);
            $this->fail('Expected current case authorization before report validation.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $generator = User::factory()->create();
        $generator->givePermissionTo(['Manage Compliance Cases', 'Read Compliance Cases']);
        $openCase = ComplianceCase::factory()->create();
        $this->actingAs($generator)->postJson("/api/compliance-cases/{$openCase->id}/closure-reports", [
            'executive_summary' => 'Open cases cannot be packaged.',
        ])->assertUnprocessable()->assertJsonValidationErrors('case');
        $this->actingAs($generator)->postJson("/api/compliance-cases/{$case->id}/closure-reports", [
            'executive_summary' => 'Server ownership is fail closed.', 'version' => 7,
        ])->assertUnprocessable()->assertJsonValidationErrors('version');

        $rejectedReport = ComplianceCaseInvestigationReport::factory()->create([
            'compliance_case_id' => $case->id,
            'version' => 2,
        ]);
        ComplianceCaseInvestigationReportReview::factory()->create([
            'compliance_case_investigation_report_id' => $rejectedReport->id,
            'decision' => ComplianceCaseInvestigationReportDecision::Rejected,
        ]);
        try {
            $service->generate($generator, $case->refresh(), ['executive_summary' => 'Rejected latest report.']);
            $this->fail('Expected a rejected latest investigation report to block closure packaging.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('case', $exception->errors());
        }
        [$boundCase] = $this->closedGovernedCase();
        foreach (range(1, 19) as $version) {
            $rejected = ComplianceCaseClosureReport::factory()->create([
                'compliance_case_id' => $boundCase->id, 'generated_by' => $generator->id, 'version' => $version,
            ]);
            ComplianceCaseClosureReportReview::factory()->create([
                'compliance_case_closure_report_id' => $rejected->id,
                'decision' => ComplianceCaseClosureReportReviewDecision::Rejected,
            ]);
        }
        $twentieth = $service->generate($generator, $boundCase, ['executive_summary' => 'Exact boundary version twenty.']);
        $this->assertSame(20, $twentieth->version);
        try {
            $service->generate($generator, $boundCase, ['executive_summary' => 'Overflow version.']);
            $this->fail('Expected closure-report version 21 to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('case', $exception->errors());
        }
        $factory = ComplianceCaseClosureReport::factory()->create()->fresh();
        $this->assertSame(ComplianceCaseStatus::Closed, $factory->complianceCase->status);
        $this->assertNotEmpty(data_get($factory->report_snapshot, 'events'));
        $this->assertNotEmpty(data_get($factory->report_snapshot, 'approved_investigation_report.review.fingerprint'));
        $this->assertSame(hash('sha256', CanonicalJson::encode($service->payload($factory))), $factory->fingerprint);
        Storage::disk('private')->assertExists($factory->report_path);

        $migration = require database_path('migrations/2026_08_25_180000_create_compliance_case_closure_reports.php');
        $migration->up();
        $migration->down();
        $this->assertTrue(Schema::hasTable('compliance_case_closure_reports'));
        $this->assertDatabaseHas('compliance_case_closure_reports', ['id' => $twentieth->id, 'fingerprint' => $twentieth->fingerprint]);
    }

    public function test_independent_manager_approves_the_exact_verified_closure_package_once(): void
    {
        $report = ComplianceCaseClosureReport::factory()->create();
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo(['Manage Compliance Cases', 'Read Compliance Cases']);

        $this->actingAs($reviewer)
            ->postJson("/api/compliance-case-closure-reports/{$report->id}/review", [
                'decision' => 'approved', 'summary' => '   ',
            ])->assertUnprocessable()->assertJsonValidationErrors('summary');

        $reviewId = $this->actingAs($reviewer)
            ->postJson("/api/compliance-case-closure-reports/{$report->id}/review", [
                'decision' => 'approved',
                'summary' => 'The retained closure package matches the independently closed case record.',
            ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('compliance_case_closure_report_reviews', [
            'id' => $reviewId,
            'compliance_case_closure_report_id' => $report->id,
            'decision' => 'approved',
            'reviewed_by' => $reviewer->id,
        ]);
        $review = ComplianceCaseClosureReportReview::query()->findOrFail($reviewId);
        $this->assertSame($report->fingerprint, data_get($review->closure_report_snapshot, 'fingerprint'));
        $this->assertSame($report->report_sha256, data_get($review->closure_report_snapshot, 'report_sha256'));
        $this->assertSame(
            hash('sha256', CanonicalJson::encode(app(ComplianceCaseClosureReportReviewManager::class)->payload($review))),
            $review->fingerprint,
        );
        $this->actingAs($reviewer)->getJson("/api/compliance-cases/{$report->compliance_case_id}/closure-reports")
            ->assertOk()->assertJsonPath('data.0.review.id', $review->id)
            ->assertJsonPath('data.0.review.fingerprint', $review->fingerprint)
            ->assertJsonMissingPath('data.0.review.closure_report_snapshot');
        Livewire::actingAs($reviewer)->test(ClosureReportsRelationManager::class, [
            'ownerRecord' => $report->complianceCase, 'pageClass' => ViewComplianceCase::class,
        ])->assertCanSeeTableRecords([$report])->assertTableActionHidden('generate')->mountTableAction('inspect', $report);
        $rendered = view('filament.compliance-case-closure-report', [
            'report' => $report->fresh()->load(['generator', 'review.reviewer']),
        ])->render();
        $this->assertStringContainsString('The retained closure package matches the independently closed case record.', $rendered);
        $this->assertStringContainsString($review->fingerprint, $rendered);

        $this->actingAs($reviewer)->postJson("/api/compliance-case-closure-reports/{$report->id}/review", [
            'decision' => 'rejected', 'summary' => 'A second decision is forbidden.',
        ])->assertUnprocessable()->assertJsonValidationErrors('report');

        try {
            $review->delete();
            $this->fail('Expected retained closure-report review evidence to be immutable.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('compliance_case_closure_report_reviews', ['id' => $review->id]);
        }
    }

    public function test_closure_package_review_separates_actors_latest_version_and_verified_bytes(): void
    {
        $report = ComplianceCaseClosureReport::factory()->create();
        $report->generator->givePermissionTo('Read Compliance Cases');
        $payload = ['decision' => 'approved', 'summary' => 'Independent exact-package approval.'];
        $this->actingAs($report->generator)->postJson("/api/compliance-case-closure-reports/{$report->id}/review", $payload)->assertForbidden();

        $closer = User::query()->findOrFail($report->complianceCase->events()->reorder()->latest('version')->value('recorded_by'));
        $closer->givePermissionTo('Read Compliance Cases');
        $this->actingAs($closer)->postJson("/api/compliance-case-closure-reports/{$report->id}/review", $payload)->assertForbidden();

        $rejector = User::factory()->create();
        $rejector->givePermissionTo(['Manage Compliance Cases', 'Read Compliance Cases']);
        $rejectionId = $this->actingAs($rejector)->postJson("/api/compliance-case-closure-reports/{$report->id}/review", [
            'decision' => 'rejected', 'summary' => 'The package narrative requires a replacement version.',
        ])->assertCreated()->json('data.id');
        $rejectedFingerprint = ComplianceCaseClosureReportReview::query()->findOrFail($rejectionId)->fingerprint;

        $replacementGenerator = User::factory()->create();
        $replacementGenerator->givePermissionTo(['Manage Compliance Cases', 'Read Compliance Cases']);
        $replacement = app(ComplianceCaseClosureReportManager::class)->generate($replacementGenerator, $report->complianceCase, [
            'executive_summary' => 'Replacement closure package for latest-version review.',
        ]);
        $this->assertDatabaseHas('compliance_case_closure_report_reviews', [
            'id' => $rejectionId, 'fingerprint' => $rejectedFingerprint, 'decision' => 'rejected',
        ]);
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo(['Manage Compliance Cases', 'Read Compliance Cases']);
        $this->actingAs($reviewer)->postJson("/api/compliance-case-closure-reports/{$report->id}/review", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('report');

        $validBytes = Storage::disk('private')->get($replacement->report_path);
        Storage::disk('private')->put($replacement->report_path, 'changed');
        $this->actingAs($reviewer)->postJson("/api/compliance-case-closure-reports/{$replacement->id}/review", $payload)->assertStatus(409);
        $this->assertDatabaseMissing('compliance_case_closure_report_reviews', ['compliance_case_closure_report_id' => $replacement->id]);
        Storage::disk('private')->put($replacement->report_path, $validBytes);
        $this->actingAs($reviewer)->postJson("/api/compliance-case-closure-reports/{$replacement->id}/review", $payload)->assertCreated();

        $factory = ComplianceCaseClosureReportReview::factory()->create()->fresh();
        $this->assertSame(
            hash('sha256', CanonicalJson::encode(app(ComplianceCaseClosureReportReviewManager::class)->payload($factory))),
            $factory->fingerprint,
        );
        $this->assertSame($factory->closureReport->fingerprint, data_get($factory->closure_report_snapshot, 'fingerprint'));

        $migration = require database_path('migrations/2026_08_25_190000_create_compliance_case_closure_report_reviews.php');
        $migration->up();
        $migration->down();
        $this->assertTrue(Schema::hasTable('compliance_case_closure_report_reviews'));
        $this->assertDatabaseHas('compliance_case_closure_report_reviews', ['id' => $factory->id]);
    }

    /** @return array{ComplianceCase,ComplianceCaseInvestigationReportReview} */
    private function closedGovernedCase(): array
    {
        $investigationApproval = ComplianceCaseInvestigationReportReview::factory()->create();
        $case = $investigationApproval->report->complianceCase;
        $resolver = User::factory()->create();
        $resolver->givePermissionTo('Manage Compliance Cases');
        app(ComplianceCaseManager::class)->record($resolver, $case, [
            'status' => ComplianceCaseStatus::Resolved->value,
            'resolution_summary' => 'The approved investigation report supports resolution.',
            'summary' => 'Resolve the governed case.',
        ]);
        $closer = User::factory()->create();
        $closer->givePermissionTo('Manage Compliance Cases');
        app(ComplianceCaseManager::class)->record($closer, $case->refresh(), [
            'status' => ComplianceCaseStatus::Closed->value,
            'closure_summary' => 'Independent closure confirms every configured gate is complete.',
            'summary' => 'Close the governed case.',
        ]);

        return [$case->refresh(), $investigationApproval->fresh()];
    }
}
