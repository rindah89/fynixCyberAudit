<?php

namespace Tests\Feature;

use App\Enums\AuditPlanStatus;
use App\Filament\Exports\AuditableEntityAssessmentExporter;
use App\Filament\Exports\AuditExporter;
use App\Filament\Exports\AuditPlanItemExporter;
use App\Filament\Resources\AuditableEntityResource\Pages\ViewAuditableEntity;
use App\Filament\Resources\AuditableEntityResource\RelationManagers\AssessmentsRelationManager;
use App\Filament\Resources\AuditPlanResource\Pages\ViewAuditPlan;
use App\Filament\Resources\AuditPlanResource\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\AuditResource\Pages\ViewAudit;
use App\Models\AuditableEntity;
use App\Models\AuditableEntityAssessment;
use App\Models\AuditEngagementBaseline;
use App\Models\AuditPlanItem;
use App\Models\Control;
use App\Models\Risk;
use App\Models\User;
use App\Services\AuditEngagementManager;
use App\Services\AuditUniverseManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuditUniversePlanningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manager_assesses_auditable_entity_with_immutable_governance_snapshot(): void
    {
        $manager = $this->manager();
        $owner = User::factory()->create();
        $risk = Risk::factory()->create(['residual_likelihood' => 4, 'residual_impact' => 4]);
        $control = Control::factory()->create();
        $entity = app(AuditUniverseManager::class)->createEntity($manager, $this->entityPayload($owner, $risk, $control));
        $assessment = app(AuditUniverseManager::class)->assess($entity, $owner, $this->assessmentPayload());

        $this->assertSame(1, $assessment->version);
        $this->assertSame(20, $assessment->inherent_score);
        $this->assertSame(12, $assessment->residual_score);
        $this->assertSame('medium', $assessment->priority_band);
        $this->assertSame($risk->id, data_get($assessment->risk_snapshots, '0.id'));
        $this->assertSame($control->id, data_get($assessment->control_snapshots, '0.id'));
        $this->assertSame(64, strlen($assessment->governance_fingerprint));
        $this->assertSame('assessed', $entity->fresh()->planning_status);
        try {
            $assessment->update(['rationale' => 'Rewritten']);
            $this->fail('Assessment history was mutable.');
        } catch (LogicException) {
            $this->assertDatabaseHas('auditable_entity_assessments', ['id' => $assessment->id, 'rationale' => $this->assessmentPayload()['rationale']]);
        }
    }

    public function test_material_entity_or_mapping_change_requires_reassessment_before_planning(): void
    {
        $manager = $this->manager();
        $owner = User::factory()->create();
        $risk = Risk::factory()->create();
        $control = Control::factory()->create();
        $entity = app(AuditUniverseManager::class)->createEntity($manager, $this->entityPayload($owner, $risk, $control));
        $assessment = app(AuditUniverseManager::class)->assess($entity, $manager, $this->assessmentPayload());
        $plan = app(AuditUniverseManager::class)->createPlan($manager, $this->planPayload($manager));
        app(AuditUniverseManager::class)->updateEntity($entity, $manager, array_merge($this->entityPayload($owner, $risk, $control), ['criticality' => 'critical']));

        $this->assertSame('reassessment_required', $entity->fresh()->planning_status);
        $this->expectException(ValidationException::class);
        app(AuditUniverseManager::class)->addPlanItem($plan, $manager, $this->itemPayload($entity, $assessment));
    }

    public function test_draft_plan_prioritizes_current_assessment_and_approval_freezes_evidence(): void
    {
        $manager = $this->manager();
        $owner = User::factory()->create();
        $risk = Risk::factory()->create();
        $control = Control::factory()->create();
        $entity = app(AuditUniverseManager::class)->createEntity($manager, $this->entityPayload($owner, $risk, $control));
        $assessment = app(AuditUniverseManager::class)->assess($entity, $manager, $this->assessmentPayload());
        $planManager = User::factory()->create();
        $plan = app(AuditUniverseManager::class)->createPlan($manager, $this->planPayload($planManager));
        $item = app(AuditUniverseManager::class)->addPlanItem($plan, $planManager, $this->itemPayload($entity, $assessment));
        $item = app(AuditUniverseManager::class)->updatePlanItem($plan, $item, $planManager, array_merge($this->draftItemPayload(), ['rationale' => 'Corrected planning rationale.']));
        $approved = app(AuditUniverseManager::class)->approvePlan($plan, $planManager);

        $this->assertSame(212, $item->priority_rank);
        $this->assertSame('Corrected planning rationale.', $item->rationale);
        $this->assertSame(AuditPlanStatus::Approved, $approved->status);
        $this->assertSame($assessment->governance_fingerprint, data_get($approved->approval_snapshot, 'items.0.entity_assessment_snapshot.assessment.governance_fingerprint'));
        $this->assertSame(64, strlen($approved->approval_fingerprint));
        try {
            $approved->update(['objective' => 'Rewritten']);
            $this->fail('Approved plan was mutable.');
        } catch (LogicException) {
            $this->assertDatabaseHas('audit_plans', ['id' => $approved->id, 'objective' => $this->planPayload($planManager)['objective']]);
        }
        try {
            $item->update(['rationale' => 'Rewritten after approval']);
            $this->fail('Approved plan item was mutable.');
        } catch (LogicException) {
            $this->assertDatabaseHas('audit_plan_items', ['id' => $item->id, 'rationale' => 'Corrected planning rationale.']);
        }
    }

    public function test_permissions_and_plan_boundaries_are_enforced_in_service(): void
    {
        $manager = $this->manager();
        $outsider = User::factory()->create();
        $owner = User::factory()->create();
        $risk = Risk::factory()->create();
        $control = Control::factory()->create();

        try {
            app(AuditUniverseManager::class)->createEntity($outsider, $this->entityPayload($owner, $risk, $control));
            $this->fail('Unauthorized entity creation succeeded.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $entity = app(AuditUniverseManager::class)->createEntity($manager, $this->entityPayload($owner, $risk, $control));
        try {
            app(AuditUniverseManager::class)->updateEntity($entity, $owner, $this->entityPayload($owner, $risk, $control));
            $this->fail('Entity owner changed manager-owned universe configuration.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $assessment = app(AuditUniverseManager::class)->assess($entity, $owner, $this->assessmentPayload());
        $plan = app(AuditUniverseManager::class)->createPlan($manager, $this->planPayload($manager));
        app(AuditUniverseManager::class)->addPlanItem($plan, $manager, $this->itemPayload($entity, $assessment));
        app(AuditUniverseManager::class)->approvePlan($plan, $manager);
        $this->expectException(ValidationException::class);
        app(AuditUniverseManager::class)->addPlanItem($plan, $manager, $this->itemPayload($entity, $assessment));
    }

    public function test_approval_revalidates_freshness_and_assessment_due_date_cannot_be_rewritten(): void
    {
        $manager = $this->manager();
        $owner = User::factory()->create();
        $risk = Risk::factory()->create();
        $control = Control::factory()->create();
        $entity = app(AuditUniverseManager::class)->createEntity($manager, $this->entityPayload($owner, $risk, $control));
        $assessment = app(AuditUniverseManager::class)->assess($entity, $manager, $this->assessmentPayload());
        $plan = app(AuditUniverseManager::class)->createPlan($manager, $this->planPayload($manager));
        app(AuditUniverseManager::class)->addPlanItem($plan, $manager, $this->itemPayload($entity, $assessment));

        try {
            app(AuditUniverseManager::class)->updateEntity($entity, $manager, array_merge($this->entityPayload($owner, $risk, $control), ['next_assessment_at' => '2028-08-24']));
            $this->fail('Assessment-owned due date was rewritten through entity maintenance.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('next_assessment_at', $exception->errors());
        }
        app(AuditUniverseManager::class)->updateEntity($entity, $manager, array_merge($this->entityPayload($owner, $risk, $control), ['criticality' => 'critical']));
        try {
            app(AuditUniverseManager::class)->approvePlan($plan, $manager);
            $this->fail('Plan approval certified stale assessment evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
        $this->assertSame(AuditPlanStatus::Draft, $plan->fresh()->status);
    }

    public function test_draft_items_can_be_removed_but_approved_items_cannot(): void
    {
        $manager = $this->manager();
        $owner = User::factory()->create();
        $risk = Risk::factory()->create();
        $control = Control::factory()->create();
        $entity = app(AuditUniverseManager::class)->createEntity($manager, $this->entityPayload($owner, $risk, $control));
        $assessment = app(AuditUniverseManager::class)->assess($entity, $manager, $this->assessmentPayload());
        $plan = app(AuditUniverseManager::class)->createPlan($manager, $this->planPayload($manager));
        $item = app(AuditUniverseManager::class)->addPlanItem($plan, $manager, $this->itemPayload($entity, $assessment));
        app(AuditUniverseManager::class)->removePlanItem($plan, $item, $manager);
        $this->assertDatabaseMissing('audit_plan_items', ['id' => $item->id]);

        $item = app(AuditUniverseManager::class)->addPlanItem($plan, $manager, $this->itemPayload($entity, $assessment));
        app(AuditUniverseManager::class)->approvePlan($plan, $manager);
        $this->expectException(ValidationException::class);
        app(AuditUniverseManager::class)->removePlanItem($plan, $item, $manager);
    }

    public function test_rest_workflow_is_scoped_and_server_owns_scores_snapshots_and_approval(): void
    {
        $manager = $this->manager();
        $owner = User::factory()->create();
        $risk = Risk::factory()->create();
        $control = Control::factory()->create();
        Sanctum::actingAs($manager);
        $entityId = $this->postJson('/api/auditable-entities', $this->entityPayload($owner, $risk, $control) + ['inherent_score' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('inherent_score');
        $entityId = $this->postJson('/api/auditable-entities', $this->entityPayload($owner, $risk, $control))
            ->assertCreated()->assertJsonPath('data.created_by', $manager->id)->json('data.id');
        $assessmentId = $this->postJson("/api/auditable-entities/{$entityId}/assessments", $this->assessmentPayload() + ['priority_band' => 'low'])
            ->assertUnprocessable()->assertJsonValidationErrors('priority_band');
        $assessmentId = $this->postJson("/api/auditable-entities/{$entityId}/assessments", $this->assessmentPayload())
            ->assertCreated()->assertJsonPath('data.inherent_score', 20)->assertJsonPath('data.residual_score', 12)
            ->assertJsonPath('data.entity_snapshot.code', 'AE-PAYMENTS')->json('data.id');
        $this->getJson('/api/auditable-entities')->assertOk()->assertJsonPath('data.0.planning_status', 'assessed');
        $this->getJson("/api/auditable-entities/{$entityId}/assessments")->assertOk()->assertJsonPath('data.0.id', $assessmentId);
        $planId = $this->postJson('/api/audit-plans', $this->planPayload($manager) + ['status' => 'approved'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $planId = $this->postJson('/api/audit-plans', $this->planPayload($manager))->assertCreated()->json('data.id');
        $this->postJson("/api/audit-plans/{$planId}/items", [
            'auditable_entity_id' => $entityId, 'auditable_entity_assessment_id' => $assessmentId,
            'status' => 'planned', 'planned_start_at' => '2027-03-01', 'planned_end_at' => '2027-04-30',
            'rationale' => 'Residual exposure and criticality warrant coverage.',
        ])->assertCreated()->assertJsonPath('data.priority_rank', 212);
        $itemId = AuditPlanItem::query()->where('audit_plan_id', $planId)->value('id');
        $this->putJson("/api/audit-plans/{$planId}/items/{$itemId}", array_merge($this->draftItemPayload(), ['rationale' => 'Corrected through REST.']))
            ->assertOk()->assertJsonPath('data.rationale', 'Corrected through REST.');
        $this->postJson("/api/audit-plans/{$planId}/approve", ['approval_fingerprint' => 'caller-owned'])
            ->assertUnprocessable()->assertJsonValidationErrors('approval_fingerprint');
        $this->postJson("/api/audit-plans/{$planId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->deleteJson("/api/audit-plans/{$planId}/items/{$itemId}")->assertUnprocessable();
        $this->getJson("/api/audit-plans/{$planId}/items")->assertOk()->assertJsonCount(1, 'data');

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->getJson('/api/auditable-entities')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/audit-plans')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/auditable-entities/{$entityId}/assessments")->assertForbidden();
        $this->getJson("/api/audit-plans/{$planId}/items")->assertForbidden();
    }

    public function test_operator_inspects_complete_scoped_assessment_and_plan_evidence_and_export_contract(): void
    {
        $manager = $this->manager();
        $owner = User::factory()->create();
        $risk = Risk::factory()->create();
        $control = Control::factory()->create();
        $entity = app(AuditUniverseManager::class)->createEntity($manager, $this->entityPayload($owner, $risk, $control));
        $assessment = app(AuditUniverseManager::class)->assess($entity, $owner, $this->assessmentPayload());
        $plan = app(AuditUniverseManager::class)->createPlan($manager, $this->planPayload($manager));
        $item = app(AuditUniverseManager::class)->addPlanItem($plan, $manager, $this->itemPayload($entity, $assessment));
        app(AuditUniverseManager::class)->approvePlan($plan, $manager);

        $this->actingAs($owner, 'web');
        Livewire::test(AssessmentsRelationManager::class, ['ownerRecord' => $entity, 'pageClass' => ViewAuditableEntity::class])
            ->assertCanSeeTableRecords([$assessment])->assertTableActionVisible('inspect', $assessment);
        $this->view('filament.auditable-entity-assessment', ['assessment' => $assessment])
            ->assertSee($assessment->rationale)->assertSee($assessment->governance_fingerprint)
            ->assertSee($risk->code)->assertSee($control->code);

        $this->actingAs($manager, 'web');
        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $plan, 'pageClass' => ViewAuditPlan::class])
            ->assertCanSeeTableRecords([$item])->assertTableActionVisible('inspect', $item);
        $this->view('filament.audit-plan-item', ['item' => $item->load(['auditableEntity', 'audit'])])
            ->assertSee($item->rationale)->assertSee($assessment->governance_fingerprint)->assertSee($entity->code);

        $assessmentColumns = collect(AuditableEntityAssessmentExporter::getColumns())->map->getName();
        $this->assertContains('entity_snapshot', $assessmentColumns);
        $this->assertContains('risk_snapshots', $assessmentColumns);
        $this->assertContains('control_snapshots', $assessmentColumns);
        $this->assertContains('governance_fingerprint', $assessmentColumns);
        $itemColumns = collect(AuditPlanItemExporter::getColumns())->map->getName();
        $this->assertContains('priority_rank', $itemColumns);
        $this->assertContains('entity_assessment_snapshot', $itemColumns);
    }

    public function test_factories_preserve_snapshot_identity_and_priority_contract(): void
    {
        $assessment = AuditableEntityAssessment::factory()->create();
        $item = AuditPlanItem::factory()->create(['auditable_entity_assessment_id' => $assessment->id]);

        $this->assertSame($assessment->auditable_entity_id, data_get($assessment->entity_snapshot, 'id'));
        $this->assertNotEmpty($assessment->risk_snapshots);
        $this->assertNotEmpty($assessment->control_snapshots);
        $this->assertSame($assessment->auditable_entity_id, $item->auditable_entity_id);
        $this->assertSame($assessment->governance_fingerprint, data_get($item->entity_assessment_snapshot, 'assessment.governance_fingerprint'));
        $this->assertSame(212, $item->priority_rank);
    }

    public function test_approved_plan_item_launches_attributable_immutable_audit_engagement_baseline(): void
    {
        $manager = $this->manager();
        $manager->givePermissionTo(['Create Audits', 'Read Audits', 'List Audits']);
        $owner = User::factory()->create();
        $risk = Risk::factory()->create();
        $control = Control::factory()->create();
        $teamMember = User::factory()->create();
        $entity = app(AuditUniverseManager::class)->createEntity($manager, $this->entityPayload($owner, $risk, $control));
        $assessment = app(AuditUniverseManager::class)->assess($entity, $manager, $this->assessmentPayload());
        $plan = app(AuditUniverseManager::class)->createPlan($manager, $this->planPayload($manager));
        $item = app(AuditUniverseManager::class)->addPlanItem($plan, $manager, $this->itemPayload($entity, $assessment));
        app(AuditUniverseManager::class)->approvePlan($plan, $manager);

        $outsider = User::factory()->create();
        $outsider->givePermissionTo('Create Audits');
        try {
            app(AuditEngagementManager::class)->launch($item, $outsider, $this->engagementPayload($manager, $teamMember));
            $this->fail('A caller outside the approved plan launched an engagement.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertDatabaseCount('audit_engagement_baselines', 0);
        }

        Sanctum::actingAs($manager);
        $this->postJson("/api/audit-plan-items/{$item->id}/launch-engagement", $this->engagementPayload($manager, $teamMember) + ['fingerprint' => str_repeat('a', 64)])
            ->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
        $this->postJson("/api/audit-plan-items/{$item->id}/launch-engagement", $this->engagementPayload($manager, $teamMember))
            ->assertCreated()->assertJsonPath('data.audit.status', 'Not Started')
            ->assertJsonPath('data.entity_assessment_snapshot.assessment.governance_fingerprint', $assessment->governance_fingerprint);
        $baseline = AuditEngagementBaseline::query()->where('audit_plan_item_id', $item->id)->firstOrFail();
        $baseline->load('audit');

        $this->assertSame('Not Started', $baseline->audit->status->value);
        $this->assertSame('2027-03-01', $baseline->audit->start_date->toDateString());
        $this->assertSame('2027-04-30', $baseline->audit->end_date->toDateString());
        $this->assertEqualsCanonicalizing([$manager->id, $teamMember->id], $baseline->team_user_ids);
        $this->assertSame('Payments control audit', data_get($baseline->audit_snapshot, 'title'));
        $this->assertSame($assessment->governance_fingerprint, data_get($baseline->entity_assessment_snapshot, 'assessment.governance_fingerprint'));
        $this->assertSame($plan->fresh()->approval_fingerprint, data_get($baseline->plan_snapshot, 'plan.approval_fingerprint'));
        $this->assertSame(64, strlen($baseline->fingerprint));
        $this->assertSame($baseline->fingerprint, $this->engagementFingerprint($baseline));
        $this->assertNull($item->fresh()->audit_id, 'Approved planning evidence was mutated during handoff.');
        $this->assertEqualsCanonicalizing([$manager->id, $teamMember->id], $baseline->audit->members()->pluck('users.id')->all());
        try {
            $baseline->update(['scope' => 'Rewritten scope']);
            $this->fail('Engagement baseline was mutable.');
        } catch (LogicException) {
            $this->assertDatabaseHas('audit_engagement_baselines', ['id' => $baseline->id, 'scope' => $this->engagementPayload($manager, $teamMember)['scope']]);
        }

        $secondItem = AuditPlanItem::factory()->create();
        $this->postJson("/api/audit-plan-items/{$secondItem->id}/launch-engagement", $this->engagementPayload($manager, $teamMember))
            ->assertUnprocessable()->assertJsonValidationErrors('plan');
        $this->postJson("/api/audit-plan-items/{$item->id}/launch-engagement", $this->engagementPayload($manager, $teamMember))
            ->assertUnprocessable()->assertJsonValidationErrors('item');
        $this->getJson('/api/audits/'.$baseline->audit_id)->assertOk()
            ->assertJsonPath('data.engagement_baseline.fingerprint', $baseline->fingerprint);

        $this->actingAs($manager, 'web');
        Livewire::test(ViewAudit::class, ['record' => $baseline->audit_id])
            ->assertSee('Approved plan engagement baseline')
            ->assertSee($baseline->objective)
            ->assertSee($assessment->governance_fingerprint)
            ->assertSee($baseline->fingerprint);

        $columns = collect(AuditExporter::getColumns())->map->getName();
        $this->assertContains('engagementBaseline.objective', $columns);
        $this->assertContains('engagementBaseline.plan_snapshot', $columns);
        $this->assertContains('engagementBaseline.entity_assessment_snapshot', $columns);
        $this->assertContains('engagementBaseline.fingerprint', $columns);
        $factoryBaseline = AuditEngagementBaseline::factory()->create();
        $this->assertSame($factoryBaseline->audit->manager_id, $factoryBaseline->team_user_ids[0]);
        $this->assertSame(AuditPlanStatus::Approved, $factoryBaseline->planItem->plan->status);
        $this->assertSame($factoryBaseline->audit_id, data_get($factoryBaseline->audit_snapshot, 'id'));
        $this->assertSame($factoryBaseline->planItem->plan->approval_fingerprint, data_get($factoryBaseline->plan_snapshot, 'plan.approval_fingerprint'));
        $this->assertSame($factoryBaseline->fingerprint, $this->engagementFingerprint($factoryBaseline));
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['Update Programs', 'Read Programs']);

        return $user;
    }

    private function entityPayload(User $owner, Risk $risk, Control $control): array
    {
        return [
            'code' => 'AE-PAYMENTS', 'name' => 'Payments process', 'description' => 'Payment authorization and settlement.',
            'entity_type' => 'business_process', 'owner_id' => $owner->id, 'criticality' => 'high', 'status' => 'active',
            'assessment_frequency' => 'annual', 'next_assessment_at' => '2027-08-24',
            'risk_ids' => [$risk->id], 'control_ids' => [$control->id],
        ];
    }

    private function assessmentPayload(): array
    {
        return ['inherent_likelihood' => 5, 'inherent_impact' => 4, 'residual_likelihood' => 3, 'residual_impact' => 4, 'rationale' => 'Material payment volume and fraud exposure.', 'next_assessment_at' => '2027-08-24'];
    }

    private function planPayload(User $manager): array
    {
        return ['plan_year' => 2027, 'name' => '2027 Internal Audit Plan', 'objective' => 'Prioritize high residual exposure and critical operations.', 'manager_id' => $manager->id];
    }

    private function itemPayload(AuditableEntity $entity, AuditableEntityAssessment $assessment): array
    {
        return ['auditable_entity_id' => $entity->id, 'auditable_entity_assessment_id' => $assessment->id, 'status' => 'planned', 'planned_start_at' => '2027-03-01', 'planned_end_at' => '2027-04-30', 'rationale' => 'Residual exposure and criticality warrant coverage.'];
    }

    private function draftItemPayload(): array
    {
        return ['status' => 'planned', 'planned_start_at' => '2027-03-01', 'planned_end_at' => '2027-04-30', 'rationale' => 'Residual exposure and criticality warrant coverage.'];
    }

    private function engagementPayload(User $manager, User $teamMember): array
    {
        return [
            'title' => 'Payments control audit',
            'description' => 'Audit launched from the approved annual plan.',
            'audit_type' => 'controls',
            'manager_id' => $manager->id,
            'objective' => 'Evaluate whether payment authorization controls are designed and operating effectively.',
            'scope' => 'Payment authorization, settlement, and mapped control evidence for the approved planning period.',
            'exclusions' => 'Treasury investments and payroll.',
            'team_user_ids' => [$teamMember->id],
        ];
    }

    private function engagementFingerprint(AuditEngagementBaseline $baseline): string
    {
        return hash('sha256', json_encode([
            'audit_snapshot' => $baseline->audit_snapshot,
            'objective' => $baseline->objective,
            'scope' => $baseline->scope,
            'exclusions' => $baseline->exclusions,
            'team_user_ids' => $baseline->team_user_ids,
            'plan_snapshot' => $baseline->plan_snapshot,
            'entity_assessment_snapshot' => $baseline->entity_assessment_snapshot,
            'launched_by' => $baseline->launched_by,
            'launched_at' => $baseline->launched_at->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }
}
