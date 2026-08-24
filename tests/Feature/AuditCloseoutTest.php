<?php

namespace Tests\Feature;

use App\Enums\Applicability;
use App\Enums\AuditCloseoutDecision;
use App\Enums\Effectiveness;
use App\Enums\WorkflowStatus;
use App\Filament\Exports\AuditCloseoutSubmissionExporter;
use App\Filament\Resources\AuditResource;
use App\Filament\Resources\AuditResource\Pages\ViewAudit;
use App\Filament\Resources\AuditResource\RelationManagers\CloseoutSubmissionsRelationManager;
use App\Models\AuditCloseoutReview;
use App\Models\AuditCloseoutSubmission;
use App\Models\AuditEngagementBaseline;
use App\Models\AuditItem;
use App\Models\DataRequest;
use App\Models\User;
use App\Services\AuditCloseoutManager;
use App\Services\AuditTeamManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuditCloseoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('private');
        setting(['storage.driver' => 'private']);
    }

    public function test_manager_submits_complete_fieldwork_and_independent_reviewer_approves_retained_report(): void
    {
        [$audit, $manager, $item] = $this->governedAudit();
        try {
            $audit->update(['status' => WorkflowStatus::COMPLETED]);
            $this->fail('A governed plan engagement bypassed closeout approval.');
        } catch (LogicException) {
            $this->assertSame(WorkflowStatus::INPROGRESS, $audit->fresh()->status);
        }
        $submission = app(AuditCloseoutManager::class)->submit($audit, $manager, $this->submissionPayload());

        $this->assertSame(1, $submission->version);
        $this->assertSame($audit->id, data_get($submission->audit_snapshot, 'id'));
        $this->assertSame($item->id, data_get($submission->audit_item_snapshots, '0.id'));
        $this->assertSame($audit->engagementBaseline->fingerprint, data_get($submission->engagement_baseline_snapshot, 'fingerprint'));
        $this->assertSame($submission->fingerprint, $this->submissionFingerprint($submission));
        try {
            $item->update(['auditor_notes' => 'Changed after submission.']);
            $this->fail('Submitted fieldwork remained mutable.');
        } catch (LogicException) {
            $this->assertDatabaseHas('audit_items', ['id' => $item->id, 'auditor_notes' => 'Completed testing and evaluated the evidence.']);
        }
        try {
            DataRequest::factory()->forAuditItem($item)->accepted()->create();
            $this->fail('A new data request was added after submission.');
        } catch (LogicException) {
            $this->assertDatabaseCount('data_requests', 0);
        }
        try {
            $audit->update(['status' => WorkflowStatus::NOTSTARTED]);
            $this->fail('Audit status changed while closeout review was pending.');
        } catch (LogicException) {
            $this->assertSame(WorkflowStatus::INPROGRESS, $audit->fresh()->status);
        }

        try {
            app(AuditCloseoutManager::class)->review($submission, $manager, $this->reviewPayload('approved'));
            $this->fail('The audit manager independently approved their own closeout.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo(['Update Audits', 'Read Audits']);
        $review = app(AuditCloseoutManager::class)->review($submission, $reviewer, $this->reviewPayload('approved'));

        $this->assertSame(AuditCloseoutDecision::Approved, $review->decision);
        $this->assertSame(WorkflowStatus::COMPLETED, $audit->fresh()->status);
        Storage::disk('private')->assertExists($review->report_path);
        $bytes = Storage::disk('private')->get($review->report_path);
        $this->assertSame(strlen($bytes), $review->report_size);
        $this->assertSame(hash('sha256', $bytes), $review->report_sha256);
        $this->assertSame($review->fingerprint, $this->reviewFingerprint($review));

        $this->actingAs($reviewer, 'web')->get(route('audit-closeout-reviews.report', $review))->assertOk();
        $this->actingAs(User::factory()->create(), 'web')->get(route('audit-closeout-reviews.report', $review))->assertForbidden();
        Storage::disk('private')->put($review->report_path, 'replaced report bytes');
        $this->actingAs($reviewer, 'web')->get(route('audit-closeout-reviews.report', $review))
            ->assertStatus(409)
            ->assertSee('no longer matches its governed fingerprint');
        try {
            $audit->fresh()->update(['status' => WorkflowStatus::INPROGRESS]);
            $this->fail('An independently approved closeout was reopened through ordinary maintenance.');
        } catch (LogicException) {
            $this->assertSame(WorkflowStatus::COMPLETED, $audit->fresh()->status);
        }
        try {
            $submission->update(['executive_summary' => 'Rewritten']);
            $this->fail('Closeout submission was mutable.');
        } catch (LogicException) {
            $this->assertDatabaseHas('audit_closeout_submissions', ['id' => $submission->id, 'executive_summary' => $this->submissionPayload()['executive_summary']]);
        }
        try {
            $review->delete();
            $this->fail('Closeout review was deletable.');
        } catch (LogicException) {
            $this->assertDatabaseHas('audit_closeout_reviews', ['id' => $review->id]);
        }
    }

    public function test_review_revalidates_fieldwork_and_team_snapshots_under_lock(): void
    {
        foreach (['fieldwork', 'team'] as $change) {
            [$audit, $manager, $item] = $this->governedAudit();
            $submission = app(AuditCloseoutManager::class)->submit($audit, $manager, $this->submissionPayload());
            $reviewer = User::factory()->create();
            $reviewer->givePermissionTo('Update Audits');

            if ($change === 'fieldwork') {
                DB::table('audit_items')->where('id', $item->id)->update(['auditor_notes' => 'Changed outside the governed write seam.']);
            } else {
                DB::table('audit_user')->insert(['audit_id' => $audit->id, 'user_id' => User::factory()->create()->id]);
            }

            try {
                app(AuditCloseoutManager::class)->review($submission, $reviewer, $this->reviewPayload('approved'));
                $this->fail("A changed {$change} snapshot was approved.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('submission', $exception->errors());
                $this->assertSame(WorkflowStatus::INPROGRESS, $audit->fresh()->status);
                $this->assertDatabaseMissing('audit_closeout_reviews', ['audit_closeout_submission_id' => $submission->id]);
            }
        }
    }

    public function test_operator_cannot_change_team_while_closeout_is_pending(): void
    {
        [$audit, $manager] = $this->governedAudit();
        $manager->givePermissionTo(['Read Audits', 'Update Audits']);
        $existingMember = User::factory()->create();
        $newMember = User::factory()->create();
        $audit->members()->sync([$existingMember->id]);
        app(AuditCloseoutManager::class)->submit($audit, $manager, $this->submissionPayload());

        $this->actingAs($manager, 'web');
        $this->assertFalse(AuditResource::canEdit($audit));
        $this->get(AuditResource::getUrl('edit', ['record' => $audit]))->assertForbidden();

        try {
            app(AuditTeamManager::class)->sync($audit, $manager, [$newMember->id]);
            $this->fail('The pending closeout team changed through the governed relationship service.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('members', $exception->errors());
        }

        $this->assertEqualsCanonicalizing([$existingMember->id], $audit->members()->pluck('users.id')->all());
    }

    public function test_closeout_requires_complete_items_and_resolved_data_requests(): void
    {
        [$audit, $manager, $item] = $this->governedAudit(false);
        try {
            app(AuditCloseoutManager::class)->submit($audit, $manager, $this->submissionPayload());
            $this->fail('Incomplete fieldwork was submitted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('audit_items', $exception->errors());
        }
        $item->update(['status' => WorkflowStatus::COMPLETED, 'auditor_notes' => 'Completed fieldwork.', 'effectiveness' => Effectiveness::EFFECTIVE, 'applicability' => Applicability::APPLICABLE]);
        $request = DataRequest::factory()->forAuditItem($item)->pending()->create();
        try {
            app(AuditCloseoutManager::class)->submit($audit, $manager, $this->submissionPayload());
            $this->fail('Unresolved evidence requests were ignored.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data_requests', $exception->errors());
        }
        $request->update(['status' => 'Accepted']);
        $this->assertSame(1, app(AuditCloseoutManager::class)->submit($audit, $manager, $this->submissionPayload())->version);
    }

    public function test_rejection_retains_history_and_allows_a_new_version(): void
    {
        [$audit, $manager] = $this->governedAudit();
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('Update Audits');
        $first = app(AuditCloseoutManager::class)->submit($audit, $manager, $this->submissionPayload());
        $rejection = app(AuditCloseoutManager::class)->review($first, $reviewer, $this->reviewPayload('rejected'));

        $this->assertSame(AuditCloseoutDecision::Rejected, $rejection->decision);
        $this->assertNull($rejection->report_path);
        $this->assertSame(WorkflowStatus::INPROGRESS, $audit->fresh()->status);
        $audit->auditItems()->firstOrFail()->update(['auditor_notes' => 'Reworked after independent rejection.']);
        $second = app(AuditCloseoutManager::class)->submit($audit, $manager, array_merge($this->submissionPayload(), ['executive_summary' => 'Revised executive summary.']));
        $this->assertSame(2, $second->version);
        $this->assertDatabaseHas('audit_closeout_submissions', ['id' => $first->id]);
        $this->expectException(ValidationException::class);
        app(AuditCloseoutManager::class)->review($first, $reviewer, $this->reviewPayload('approved'));
    }

    public function test_rest_operator_export_and_factories_expose_scoped_closeout_evidence(): void
    {
        [$audit, $manager] = $this->governedAudit();
        $manager->givePermissionTo('Read Audits');
        Sanctum::actingAs($manager);
        $this->postJson("/api/audits/{$audit->id}/closeouts", $this->submissionPayload() + ['fingerprint' => str_repeat('a', 64)])
            ->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $submissionId = $this->postJson("/api/audits/{$audit->id}/closeouts", $this->submissionPayload())
            ->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo(['Update Audits', 'Read Audits']);
        Sanctum::actingAs($reviewer);
        $this->postJson("/api/audit-closeout-submissions/{$submissionId}/review", $this->reviewPayload('approved') + ['report_sha256' => str_repeat('b', 64)])
            ->assertUnprocessable()->assertJsonValidationErrors('report_sha256');
        $this->postJson("/api/audit-closeout-submissions/{$submissionId}/review", $this->reviewPayload('approved'))
            ->assertCreated()->assertJsonPath('data.decision', 'approved')->assertJsonPath('data.report_size', fn ($size): bool => $size > 0);
        $this->getJson("/api/audits/{$audit->id}/closeouts")->assertOk()->assertJsonPath('data.0.id', $submissionId);

        $submission = AuditCloseoutSubmission::query()->findOrFail($submissionId);
        $this->actingAs($manager, 'web');
        Livewire::test(CloseoutSubmissionsRelationManager::class, ['ownerRecord' => $audit, 'pageClass' => ViewAudit::class])
            ->assertCanSeeTableRecords([$submission])->assertTableActionVisible('inspect', $submission);
        $this->view('filament.audit-closeout-submission', ['submission' => $submission->load(['submitter', 'review.reviewer'])])
            ->assertSee($submission->executive_summary)->assertSee($submission->fingerprint)->assertSee($submission->review->report_sha256);

        $columns = collect(AuditCloseoutSubmissionExporter::getColumns())->map->getName();
        $this->assertContains('audit_item_snapshots', $columns);
        $this->assertContains('review.report_sha256', $columns);
        $factorySubmission = AuditCloseoutSubmission::factory()->create();
        $factoryReview = AuditCloseoutReview::factory()->create(['audit_closeout_submission_id' => $factorySubmission->id]);
        $this->assertSame($factorySubmission->fingerprint, $this->submissionFingerprint($factorySubmission));
        $this->assertSame($factoryReview->fingerprint, $this->reviewFingerprint($factoryReview));
        $migration = require database_path('migrations/2026_08_24_380000_create_audit_closeout_evidence.php');
        $migration->up();
        $migration->down();
        $this->assertDatabaseHas('audit_closeout_submissions', ['id' => $factorySubmission->id]);
        $this->assertDatabaseHas('audit_closeout_reviews', ['id' => $factoryReview->id]);
    }

    private function governedAudit(bool $completeItem = true): array
    {
        $baseline = AuditEngagementBaseline::factory()->create();
        $audit = $baseline->audit;
        $audit->update(['status' => WorkflowStatus::INPROGRESS]);
        $manager = $audit->manager;
        $item = AuditItem::factory()->for($audit)->create([
            'status' => $completeItem ? WorkflowStatus::COMPLETED : WorkflowStatus::INPROGRESS,
            'auditor_notes' => $completeItem ? 'Completed testing and evaluated the evidence.' : null,
            'effectiveness' => $completeItem ? Effectiveness::EFFECTIVE : Effectiveness::UNKNOWN,
            'applicability' => $completeItem ? Applicability::APPLICABLE : Applicability::UNKNOWN,
        ]);

        return [$audit->fresh('engagementBaseline'), $manager, $item];
    }

    private function submissionPayload(): array
    {
        return [
            'opinion' => 'needs_improvement', 'executive_summary' => 'Controls generally operate, with defined improvement opportunities.',
            'scope_limitations' => 'One archived population was unavailable.', 'significant_matters' => 'Access recertification evidence was incomplete.',
            'recommendations_summary' => 'Automate quarterly recertification and retain reviewer evidence.',
        ];
    }

    private function reviewPayload(string $decision): array
    {
        return ['decision' => $decision, 'review_summary' => 'The conclusion is supported by the retained fieldwork snapshot and documented limitations.'];
    }

    private function submissionFingerprint(AuditCloseoutSubmission $submission): string
    {
        return hash('sha256', json_encode([
            'audit_snapshot' => $submission->audit_snapshot, 'engagement_baseline_snapshot' => $submission->engagement_baseline_snapshot,
            'audit_item_snapshots' => $submission->audit_item_snapshots, 'data_request_snapshots' => $submission->data_request_snapshots,
            'opinion' => $submission->opinion->value, 'executive_summary' => $submission->executive_summary,
            'scope_limitations' => $submission->scope_limitations, 'significant_matters' => $submission->significant_matters,
            'recommendations_summary' => $submission->recommendations_summary, 'submitted_by' => $submission->submitted_by,
            'submitted_at' => $submission->submitted_at->toIso8601String(), 'version' => $submission->version,
        ], JSON_THROW_ON_ERROR));
    }

    private function reviewFingerprint(AuditCloseoutReview $review): string
    {
        return hash('sha256', json_encode($review->report_snapshot + [
            'report_disk' => $review->report_disk, 'report_path' => $review->report_path,
            'report_size' => $review->report_size, 'report_sha256' => $review->report_sha256,
        ], JSON_THROW_ON_ERROR));
    }
}
