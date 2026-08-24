<?php

namespace Tests\Feature;

use App\Enums\Applicability;
use App\Enums\Effectiveness;
use App\Enums\WorkflowStatus;
use App\Filament\Exports\AuditFindingExporter;
use App\Filament\Resources\AuditResource\Pages\ViewAudit;
use App\Filament\Resources\AuditResource\RelationManagers\GovernedFindingsRelationManager;
use App\Models\AuditEngagementBaseline;
use App\Models\AuditFinding;
use App\Models\AuditItem;
use App\Models\AuditManagementResponse;
use App\Models\User;
use App\Services\AuditCloseoutManager;
use App\Services\AuditFindingManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuditFindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manager_raises_immutable_finding_and_owner_versions_attributable_response(): void
    {
        [$audit, $manager, $owner, $item] = $this->context();
        $finding = app(AuditFindingManager::class)->raise($audit, $manager, $this->findingPayload($item, $owner));
        $first = app(AuditFindingManager::class)->respond($finding, $owner, $this->responsePayload());
        $second = app(AuditFindingManager::class)->respond($finding, $owner, $this->responsePayload() + ['response' => 'Management confirms funding and delivery ownership.']);

        $this->assertSame('AF-'.str_pad((string) $audit->id, 6, '0', STR_PAD_LEFT).'-001', $finding->code);
        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame($finding->fingerprint, data_get($first->finding_snapshot, 'fingerprint'));
        $this->assertSame($finding->fingerprint, $this->findingFingerprint($finding));
        $this->assertSame($second->fingerprint, $this->responseFingerprint($second));
        $this->expectException(LogicException::class);
        $finding->update(['title' => 'Rewritten']);
    }

    public function test_only_accountable_owner_can_respond_and_agreement_requires_action_and_target(): void
    {
        [$audit, $manager, $owner, $item] = $this->context();
        $finding = app(AuditFindingManager::class)->raise($audit, $manager, $this->findingPayload($item, $owner));
        $outsider = User::factory()->create();
        try {
            app(AuditFindingManager::class)->respond($finding, $outsider, $this->responsePayload());
            $this->fail('An outsider supplied management response evidence.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->expectException(ValidationException::class);
        app(AuditFindingManager::class)->respond($finding, $owner, ['position' => 'agreed', 'response' => 'Agreed.']);
    }

    public function test_aggregate_evidence_bound_rolls_back_a_response_before_closeout_payload_growth(): void
    {
        [$audit, $manager, $owner, $item] = $this->context();
        $finding = app(AuditFindingManager::class)->raise($audit, $manager, $this->findingPayload($item, $owner));
        DB::table('audit_findings')->where('id', $finding->id)->update(['source_snapshot' => json_encode(['adversarial' => str_repeat('X', AuditFindingManager::MAX_EVIDENCE_BYTES)], JSON_THROW_ON_ERROR)]);

        try {
            app(AuditFindingManager::class)->respond($finding->fresh(), $owner, $this->responsePayload());
            $this->fail('An oversized governed finding payload was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('audit_findings', $exception->errors());
        }
        $this->assertDatabaseCount('audit_management_responses', 0);
    }

    public function test_rest_owns_finding_and_response_evidence_and_exposes_paginated_history(): void
    {
        [$audit, $manager, $owner, $item] = $this->context();
        $manager->givePermissionTo('Read Audits');
        Sanctum::actingAs($manager);
        $this->postJson("/api/audits/{$audit->id}/governed-findings", $this->findingPayload($item, $owner) + ['code' => 'FAKE'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $findingId = $this->postJson("/api/audits/{$audit->id}/governed-findings", $this->findingPayload($item, $owner))
            ->assertCreated()->assertJsonPath('data.accountable_owner_id', $owner->id)->json('data.id');
        Sanctum::actingAs($owner);
        $this->postJson("/api/audit-findings/{$findingId}/management-responses", $this->responsePayload() + ['version' => 8])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->postJson("/api/audit-findings/{$findingId}/management-responses", $this->responsePayload())->assertCreated()->assertJsonPath('data.version', 1);
        $this->getJson("/api/audit-findings/{$findingId}")->assertOk()->assertJsonPath('data.accountable_owner_id', $owner->id)
            ->assertJsonPath('data.responses.0.version', 1)->assertJsonMissingPath('data.audit_item');
        Sanctum::actingAs($manager);
        $this->getJson("/api/audits/{$audit->id}/governed-findings?per_page=0")->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson("/api/audits/{$audit->id}/governed-findings")->assertOk()->assertJsonPath('data.0.id', $findingId)->assertJsonPath('data.0.responses.0.responded_by', $owner->id);
    }

    public function test_closeout_requires_and_retains_management_response_history(): void
    {
        [$audit, $manager, $owner, $item] = $this->context();
        $item->update(['status' => WorkflowStatus::COMPLETED, 'auditor_notes' => 'Finding documented.', 'effectiveness' => Effectiveness::INEFFECTIVE]);
        $finding = app(AuditFindingManager::class)->raise($audit, $manager, $this->findingPayload($item, $owner));
        try {
            app(AuditCloseoutManager::class)->submit($audit, $manager, $this->closeoutPayload());
            $this->fail('Closeout omitted the accountable management response.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('audit_findings', $exception->errors());
        }
        $response = app(AuditFindingManager::class)->respond($finding, $owner, $this->responsePayload());
        $submission = app(AuditCloseoutManager::class)->submit($audit, $manager, $this->closeoutPayload());
        $this->assertSame($finding->fingerprint, data_get($submission->audit_finding_snapshots, '0.fingerprint'));
        $this->assertSame($response->fingerprint, data_get($submission->audit_finding_snapshots, '0.responses.0.fingerprint'));
        $manager->givePermissionTo('Read Audits');
        $this->actingAs($manager, 'web');
        Livewire::test(GovernedFindingsRelationManager::class, ['ownerRecord' => $audit, 'pageClass' => ViewAudit::class])
            ->assertTableActionHidden('raise_finding');
        $owner->givePermissionTo('Read Audits');
        $this->actingAs($owner, 'web');
        Livewire::test(GovernedFindingsRelationManager::class, ['ownerRecord' => $audit, 'pageClass' => ViewAudit::class])
            ->assertTableActionHidden('respond', $finding);
    }

    public function test_operator_export_factories_and_migration_expose_complete_evidence(): void
    {
        [$audit, $manager, $owner, $item] = $this->context();
        $manager->givePermissionTo('Read Audits');
        $finding = app(AuditFindingManager::class)->raise($audit, $manager, $this->findingPayload($item, $owner));
        $response = app(AuditFindingManager::class)->respond($finding, $owner, $this->responsePayload());
        $this->actingAs($manager, 'web');
        Livewire::test(GovernedFindingsRelationManager::class, ['ownerRecord' => $audit, 'pageClass' => ViewAudit::class])
            ->assertCanSeeTableRecords([$finding])->assertTableActionVisible('inspect', $finding);
        $this->view('filament.audit-finding', ['finding' => $finding->load(['accountableOwner', 'raiser', 'responses.respondent'])])
            ->assertSee($finding->condition)->assertSee($response->response)->assertSee($response->fingerprint);
        $columns = collect(AuditFindingExporter::getColumns())->map->getName();
        $this->assertContains('source_snapshot', $columns);
        $this->assertContains('responses', $columns);
        $factoryResponse = AuditManagementResponse::factory()->create();
        $this->assertSame($factoryResponse->finding->accountable_owner_id, $factoryResponse->responded_by);
        $this->assertSame($factoryResponse->finding->fingerprint, data_get($factoryResponse->finding_snapshot, 'fingerprint'));
        $migration = require database_path('migrations/2026_08_24_420000_create_governed_audit_findings.php');
        $migration->up();
        $migration->down();
        $this->assertDatabaseHas('audit_management_responses', ['id' => $factoryResponse->id]);
    }

    private function context(): array
    {
        $baseline = AuditEngagementBaseline::factory()->create();
        $audit = $baseline->audit;
        $audit->update(['status' => WorkflowStatus::INPROGRESS]);
        $item = AuditItem::factory()->for($audit)->create(['effectiveness' => Effectiveness::INEFFECTIVE, 'applicability' => Applicability::APPLICABLE]);

        return [$audit, $audit->manager, User::factory()->create(), $item];
    }

    private function findingPayload(AuditItem $item, User $owner): array
    {
        return ['audit_item_id' => $item->id, 'title' => 'Access reviews lack evidence', 'severity' => 'high',
            'condition' => 'Two reviews lacked timestamps.', 'criteria' => 'Quarterly reviews require attributable completion.',
            'cause' => 'Workflow validation is incomplete.', 'effect' => 'Access may remain inappropriate.',
            'recommendation' => 'Require reviewer identity and completion timestamp.', 'accountable_owner_id' => $owner->id];
    }

    private function responsePayload(): array
    {
        return ['position' => 'agreed', 'response' => 'Management agrees.', 'action_plan' => 'Implement required identity and timestamp fields.', 'target_date' => now()->addMonth()->toDateString()];
    }

    private function closeoutPayload(): array
    {
        return ['opinion' => 'needs_improvement', 'executive_summary' => 'The audit identified a control weakness.', 'scope_limitations' => null,
            'significant_matters' => 'Access review evidence was incomplete.', 'recommendations_summary' => 'Implement attributable completion controls.'];
    }

    private function findingFingerprint(AuditFinding $finding): string
    {
        return hash('sha256', json_encode([
            'audit_item_id' => $finding->audit_item_id, 'title' => $finding->title, 'severity' => $finding->severity->value,
            'condition' => $finding->condition, 'criteria' => $finding->criteria, 'cause' => $finding->cause,
            'effect' => $finding->effect, 'recommendation' => $finding->recommendation, 'accountable_owner_id' => $finding->accountable_owner_id,
            'audit_id' => $finding->audit_id, 'code' => $finding->code, 'source_snapshot' => $finding->source_snapshot,
            'raised_by' => $finding->raised_by, 'raised_at' => $finding->raised_at->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    private function responseFingerprint(AuditManagementResponse $response): string
    {
        return hash('sha256', json_encode([
            'position' => $response->position->value, 'response' => $response->response, 'action_plan' => $response->action_plan,
            'target_date' => $response->target_date?->toDateString(), 'audit_finding_id' => $response->audit_finding_id,
            'version' => $response->version, 'finding_snapshot' => $response->finding_snapshot,
            'responded_by' => $response->responded_by, 'responded_at' => $response->responded_at->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }
}
