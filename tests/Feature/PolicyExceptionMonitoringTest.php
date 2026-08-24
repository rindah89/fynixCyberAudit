<?php

namespace Tests\Feature;

use App\Filament\Exports\PolicyExceptionExporter;
use App\Filament\Resources\PolicyResource\Pages\ViewPolicy;
use App\Filament\Resources\PolicyResource\RelationManagers\ExceptionsRelationManager;
use App\Models\Audit;
use App\Models\DataRequest;
use App\Models\DataRequestResponse;
use App\Models\FileAttachment;
use App\Models\Policy;
use App\Models\PolicyExceptionMonitoringReview;
use App\Models\User;
use App\PolicyCompliance\PolicyExceptionGovernanceManager;
use App\PolicyCompliance\PolicyExceptionMonitoringManager;
use App\PolicyCompliance\PolicyRevisionManager;
use App\Remediation\Remediation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PolicyExceptionMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.remediation', true);
        Storage::fake('private');
    }

    public function test_independent_editor_records_attributable_periodic_exception_review(): void
    {
        $requester = User::factory()->create();
        $approver = User::factory()->create();
        $monitor = User::factory()->create();
        $approver->givePermissionTo('Update Policies');
        $monitor->givePermissionTo('Update Policies');
        $policy = Policy::factory()->create(['owner_id' => $requester->id]);
        $manager = app(PolicyExceptionGovernanceManager::class);
        $exception = $manager->submit($policy, $requester, [
            'name' => 'Temporary privileged access exception',
            'description' => 'A bounded deviation.',
            'justification' => 'A legacy dependency remains.',
            'risk_assessment' => 'Elevated access exposure.',
            'compensating_controls' => 'Weekly privileged-account reconciliation.',
            'effective_date' => now()->toDateString(),
            'expiration_date' => now()->addMonths(6)->toDateString(),
            'review_frequency_days' => 30,
        ]);
        $manager->decide($exception, $approver, [
            'decision' => 'approved',
            'decision_summary' => 'Approved with monthly monitoring.',
        ]);

        Sanctum::actingAs($monitor);
        $this->postJson("/api/policy-exceptions/{$exception->id}/monitoring-reviews", [
            'outcome' => 'effective',
            'review_summary' => 'The compensating control operated during the review period.',
            'control_effectiveness' => 'Weekly reconciliations were completed and exceptions resolved.',
            'evidence_reference' => 'AUDIT-REQ-481',
            'reviewed_by' => $requester->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('reviewed_by');

        $response = $this->postJson("/api/policy-exceptions/{$exception->id}/monitoring-reviews", [
            'outcome' => 'effective',
            'review_summary' => 'The compensating control operated during the review period.',
            'control_effectiveness' => 'Weekly reconciliations were completed and exceptions resolved.',
            'evidence_reference' => 'AUDIT-REQ-481',
        ])->assertCreated()
            ->assertJsonPath('data.reviewed_by', $monitor->id)
            ->assertJsonPath('data.exception_snapshot.id', $exception->id)
            ->assertJsonPath('data.exception_snapshot.latest_decision.decision', 'approved')
            ->json('data');

        $this->assertSame(now()->addDays(30)->toDateString(), substr($response['next_review_at'], 0, 10));
        $this->assertDatabaseHas('policy_exception_monitoring_reviews', [
            'policy_exception_id' => $exception->id,
            'reviewed_by' => $monitor->id,
            'outcome' => 'effective',
        ]);
        $this->assertDatabaseHas('policy_exceptions', [
            'id' => $exception->id,
            'review_frequency_days' => 30,
            'latest_monitoring_outcome' => 'effective',
        ]);

        $this->getJson("/api/policy-exceptions/{$exception->id}/monitoring-reviews?per_page=1")
            ->assertOk()->assertJsonPath('per_page', 1)->assertJsonCount(1, 'data');

        $review = PolicyExceptionMonitoringReview::query()->firstOrFail();
        $reviewPayload = [
            'policy_exception_id' => $review->policy_exception_id,
            'version' => $review->version,
            'outcome' => $review->outcome->value,
            'review_summary' => $review->review_summary,
            'control_effectiveness' => $review->control_effectiveness,
            'evidence_reference' => $review->evidence_reference,
            'exception_snapshot' => $review->exception_snapshot,
            'reviewed_by' => $review->reviewed_by,
            'reviewed_at' => $review->reviewed_at->toISOString(),
            'next_review_at' => $review->next_review_at->toISOString(),
        ];
        $this->assertSame(hash('sha256', json_encode($reviewPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $review->fingerprint);
        $this->assertSame('Approved with monthly monitoring.', $review->exception_snapshot['latest_decision']['decision_summary']);
        $this->actingAs($monitor, 'web');
        Livewire::test(ExceptionsRelationManager::class, ['ownerRecord' => $policy, 'pageClass' => ViewPolicy::class])
            ->assertCanSeeTableRecords([$exception->fresh()])->assertTableActionVisible('inspect', $exception->fresh());
        $this->view('filament.policy-exception-governance', [
            'exception' => $exception->fresh()->load(['requester', 'decisions.decider', 'monitoringReviews.reviewer']),
        ])->assertSee('Monitoring review v1')->assertSee('AUDIT-REQ-481')->assertSee($review->fingerprint);
        $this->assertContains('monitoring_history', collect(PolicyExceptionExporter::getColumns())->map->getName());

        try {
            $review->update(['review_summary' => 'Rewritten']);
            $this->fail('Monitoring history was mutable.');
        } catch (\LogicException) {
            $this->assertNotSame('Rewritten', $review->fresh()->review_summary);
        }

        $migration = require database_path('migrations/2026_08_24_480000_create_policy_exception_monitoring.php');
        $migration->down();
        $this->assertDatabaseHas('policy_exception_monitoring_reviews', ['id' => $review->id]);

        $factoryReview = PolicyExceptionMonitoringReview::factory()->create();
        $factoryPayload = [
            'policy_exception_id' => $factoryReview->policy_exception_id,
            'version' => $factoryReview->version,
            'outcome' => $factoryReview->outcome->value,
            'review_summary' => $factoryReview->review_summary,
            'control_effectiveness' => $factoryReview->control_effectiveness,
            'evidence_reference' => $factoryReview->evidence_reference,
            'exception_snapshot' => $factoryReview->exception_snapshot,
            'reviewed_by' => $factoryReview->reviewed_by,
            'reviewed_at' => $factoryReview->reviewed_at->toISOString(),
            'next_review_at' => $factoryReview->next_review_at->toISOString(),
        ];
        $this->assertSame(hash('sha256', json_encode($factoryPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $factoryReview->fingerprint);
        $this->assertSame(90, $factoryReview->exception->review_frequency_days);
    }

    public function test_monitoring_reauthorizes_rejects_stale_effective_conclusions_and_derives_action_state(): void
    {
        $requester = User::factory()->create();
        $approver = User::factory()->create();
        $monitor = User::factory()->create();
        $outsider = User::factory()->create();
        $approver->givePermissionTo('Update Policies');
        $monitor->givePermissionTo('Update Policies');
        $policy = Policy::factory()->create(['owner_id' => $requester->id]);
        $governance = app(PolicyExceptionGovernanceManager::class);
        $exception = $governance->submit($policy, $requester, [
            'name' => 'Temporary exception', 'description' => null,
            'justification' => 'Temporary dependency.', 'risk_assessment' => 'Elevated exposure.',
            'compensating_controls' => 'Manual review.', 'effective_date' => now()->toDateString(),
            'expiration_date' => now()->addDays(10)->toDateString(), 'review_frequency_days' => 30,
        ]);
        $governance->decide($exception, $approver, ['decision' => 'approved', 'decision_summary' => 'Approved.']);
        $this->assertSame($exception->expiration_date->toDateString(), $exception->fresh()->next_review_at->toDateString());

        $manager = app(PolicyExceptionMonitoringManager::class);
        $payload = [
            'outcome' => 'effective', 'review_summary' => 'Review completed.',
            'control_effectiveness' => 'Control operated.', 'evidence_reference' => null,
        ];
        foreach ([$requester, $approver, $outsider] as $actor) {
            try {
                $manager->review($exception, $actor, $payload);
                $this->fail('An unauthorized or non-independent actor recorded monitoring evidence.');
            } catch (HttpException|ValidationException) {
                $this->assertDatabaseCount('policy_exception_monitoring_reviews', 0);
            }
        }

        app(PolicyRevisionManager::class)->submit($policy, $requester, [
            'change_summary' => 'A pending revision changes the governed approval context.',
            'proposed_effective_date' => now()->addDay()->toDateString(),
        ]);
        try {
            $manager->review($exception, $monitor, $payload);
            $this->fail('A stale approval context was confirmed effective.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('policy_exception_monitoring_reviews', 0);
        }

        $review = $manager->review($exception, $monitor, array_merge($payload, [
            'outcome' => 'needs_action',
            'review_summary' => 'Policy context changed and the exception needs reassessment.',
        ]));
        $this->assertFalse($review->exception_snapshot['approval_context_current']);
        $this->assertSame('action_required', $exception->fresh()->monitoring_status->value);
        $this->assertSame('open', $review->issue->status->value);
        $this->assertSame($policy->owner_id, $review->issue->owner_id);
        $this->assertDatabaseHas('governance_issue_lifecycles', [
            'issue_type' => 'App\\Models\\PolicyExceptionMonitoringIssue',
            'issue_id' => $review->issue->id,
            'status' => 'open',
        ]);

        $lifecycleId = $review->issue->lifecycle->id;
        DB::table('governance_issue_transitions')->where('governance_issue_lifecycle_id', $lifecycleId)->delete();
        DB::table('governance_issue_lifecycles')->whereKey($lifecycleId)->delete();
        $migration = require database_path('migrations/2026_08_24_490000_create_policy_exception_monitoring_issues.php');
        $migration->up();
        $this->assertDatabaseHas('governance_issue_lifecycles', [
            'issue_type' => 'App\\Models\\PolicyExceptionMonitoringIssue', 'issue_id' => $review->issue->id, 'status' => 'open',
        ]);
        $this->assertDatabaseHas('governance_issue_transitions', [
            'to_status' => 'open', 'transitioned_by' => $monitor->id,
        ]);

        $governance->decide($exception->fresh(), $approver, [
            'decision' => 'revoked', 'decision_summary' => 'The policy context changed and the exception is revoked.',
        ]);
        $this->assertSame('action_required', $exception->fresh()->monitoring_status->value);

        $monitor->givePermissionTo(['Manage Issue Lifecycle', 'Manage Remediation']);
        $project = app(Remediation::class)->createProject($monitor, ['name' => 'Policy exception corrective action']);
        Sanctum::actingAs($monitor);
        $this->postJson("/api/governance-issues/policy_exception/{$review->issue->id}/remediation", [
            'remediation_project_id' => $project->id,
            'priority' => 'High',
            'due_date' => now()->addDays(7)->toDateString(),
            'rationale' => 'Restore policy compliance or revoke the exception.',
        ])->assertCreated()->assertJsonPath('data.status', 'in_remediation')
            ->assertJsonPath('data.issue.status', 'in_remediation');
        $this->assertSame('action_required', $exception->fresh()->monitoring_status->value);

        $task = $review->issue->fresh()->remediationTask;
        app(Remediation::class)->updateTaskStatus($monitor, $task, 'Completed');
        $this->postJson("/api/governance-issues/policy_exception/{$review->issue->id}/request-verification", [
            'rationale' => 'Corrective action is ready for independent verification.',
        ])->assertOk()->assertJsonPath('data.status', 'verification');

        $verifier = User::factory()->create();
        $verifier->givePermissionTo('Verify Issue Closure');
        $audit = Audit::factory()->create(['manager_id' => $verifier->id]);
        $request = DataRequest::factory()->create([
            'audit_id' => $audit->id, 'created_by_id' => $verifier->id, 'assigned_to_id' => $verifier->id,
        ]);
        $response = DataRequestResponse::factory()->accepted()->create([
            'data_request_id' => $request->id, 'requester_id' => $verifier->id, 'requestee_id' => $verifier->id,
        ]);
        $path = 'closures/policy-exception-monitoring.txt';
        Storage::disk('private')->put($path, 'verified policy exception corrective-action bytes');
        $attachment = FileAttachment::query()->create([
            'data_request_response_id' => $response->id, 'audit_id' => $audit->id,
            'file_name' => 'policy-exception-monitoring.txt', 'file_path' => $path,
            'file_size' => strlen('verified policy exception corrective-action bytes'),
            'description' => 'Independent closure evidence', 'uploaded_by' => $verifier->id,
        ]);
        Sanctum::actingAs($verifier);
        $this->postJson("/api/governance-issues/policy_exception/{$review->issue->id}/close", [
            'verification_summary' => 'The corrective action restored the required policy control.',
            'evidence_attachment_ids' => [$attachment->id],
        ])->assertOk()->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.issue.status', 'closed');
        $this->assertSame('revoked', $exception->fresh()->monitoring_status->value);
        $migration->down();
        $this->assertDatabaseHas('policy_exception_monitoring_issues', ['id' => $review->issue->id, 'status' => 'closed']);
        $this->assertDatabaseHas('governance_issue_lifecycles', [
            'issue_type' => 'App\\Models\\PolicyExceptionMonitoringIssue', 'issue_id' => $review->issue->id, 'status' => 'closed',
        ]);
    }
}
