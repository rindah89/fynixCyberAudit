<?php

namespace Tests\Feature;

use App\AiGovernance\AiGovernanceManager;
use App\Enums\AiGovernanceDecisionType;
use App\Filament\Resources\AiSystemResource;
use App\Models\AiGovernanceIssue;
use App\Models\AiRiskAssessment;
use App\Models\AiSystem;
use App\Models\AiUseCase;
use App\Models\Control;
use App\Models\Risk;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manager_can_inventory_ai_system_and_register_use_case(): void
    {
        $manager = $this->manager();
        Sanctum::actingAs($manager);

        $systemId = $this->postJson('/api/ai-systems', [
            'code' => 'AI-CUSTOMER-SUPPORT',
            'name' => 'Customer support assistant',
            'owner_id' => $manager->id,
            'provider_name' => 'OpenAI',
            'model_name' => 'gpt-4.1-mini',
            'deployment_type' => 'external_api',
            'lifecycle_status' => 'pilot',
            'criticality' => 'high',
            'intended_purpose' => 'Draft support responses for human review.',
            'human_oversight' => 'Support agent approves every response.',
            'data_categories' => ['customer_contact', 'support_history'],
            'next_review_at' => now()->addMonths(6),
        ])->assertCreated()->assertJsonPath('data.governance_status', 'use_case_required')->json('data.id');

        $this->postJson("/api/ai-systems/{$systemId}/use-cases", [
            'name' => 'Draft customer support replies',
            'owner_id' => $manager->id,
            'purpose' => 'Suggest replies that an agent reviews before sending.',
            'decision_impact' => 'medium',
            'affected_population' => 'Customers contacting support',
            'uses_personal_data' => true,
            'uses_sensitive_data' => false,
            'automated_decision' => false,
        ])->assertCreated()
            ->assertJsonPath('data.governance_status', 'assessment_required')
            ->assertJsonPath('system.governance_status', 'assessment_required');
    }

    public function test_assessment_versions_and_scores_are_server_derived(): void
    {
        $manager = $this->manager();
        $useCase = AiUseCase::factory()->create(['owner_id' => $manager->id]);
        Sanctum::actingAs($manager);
        $payload = [
            'likelihood' => 4, 'impact' => 5, 'residual_likelihood' => 2, 'residual_impact' => 3,
            'risk_categories' => ['fairness', 'privacy', 'security'],
            'assessment_summary' => 'Personal data and hallucination risks require review and access controls.',
            'mitigation_summary' => 'Human review, retrieval boundaries, logging, and access control.',
        ];

        $this->postJson("/api/ai-use-cases/{$useCase->id}/assessments", $payload + ['version' => 99, 'residual_score' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['version', 'residual_score']);

        $this->postJson("/api/ai-use-cases/{$useCase->id}/assessments", $payload)
            ->assertCreated()->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.inherent_score', 20)->assertJsonPath('data.residual_score', 6)
            ->assertJsonPath('data.risk_tier', 'moderate');
        $this->postJson("/api/ai-use-cases/{$useCase->id}/assessments", $payload)
            ->assertCreated()->assertJsonPath('data.version', 2);
    }

    public function test_approval_requires_current_assessment_control_and_risk_mappings(): void
    {
        Carbon::setTestNow('2026-08-23 12:00:00');
        $manager = $this->manager();
        $useCase = AiUseCase::factory()->create(['owner_id' => $manager->id]);
        AiRiskAssessment::factory()->create(['ai_use_case_id' => $useCase->id, 'assessor_id' => $manager->id]);
        $control = Control::factory()->create();
        $risk = Risk::factory()->create();
        Sanctum::actingAs($manager);

        $decisionPayload = [
            'decision' => 'approved', 'rationale' => 'Residual risk is acceptable with mapped safeguards.',
            'conditions' => 'Maintain human review and monthly quality sampling.',
            'expires_at' => '2027-02-23', 'next_monitoring_at' => '2026-11-23',
        ];
        $this->postJson("/api/ai-use-cases/{$useCase->id}/decisions", $decisionPayload)
            ->assertUnprocessable()->assertJsonValidationErrors(['controls', 'risks']);

        $this->postJson("/api/ai-use-cases/{$useCase->id}/controls", ['control_id' => $control->id])->assertCreated();
        $this->postJson("/api/ai-use-cases/{$useCase->id}/risks", ['risk_id' => $risk->id])->assertCreated();
        $this->postJson("/api/ai-use-cases/{$useCase->id}/decisions", $decisionPayload)
            ->assertCreated()->assertJsonPath('data.decision', 'approved')
            ->assertJsonPath('data.assessment_version', 1)
            ->assertJsonPath('data.residual_score', 9)
            ->assertJsonPath('data.controls_count', 1)->assertJsonPath('data.risks_count', 1)
            ->assertJsonPath('use_case.governance_status', 'approved');

        $useCase->aiSystem->update(['prohibited_uses' => 'No autonomous eligibility decisions.']);
        $this->assertSame('reapproval_required', $useCase->fresh()->governance_status);
        $useCase->aiSystem->update(['prohibited_uses' => null]);
        $this->assertSame('approved', $useCase->fresh()->governance_status);
        $useCase->aiSystem->update(['lifecycle_status' => 'suspended']);
        $this->assertSame('suspended', $useCase->aiSystem->fresh()->governance_status);
        $useCase->aiSystem->update(['lifecycle_status' => 'pilot']);

        $this->postJson("/api/ai-use-cases/{$useCase->id}/assessments", [
            'likelihood' => 3, 'impact' => 4, 'residual_likelihood' => 2, 'residual_impact' => 2,
            'risk_categories' => ['fairness'], 'assessment_summary' => 'Reassessment after a material change.',
            'mitigation_summary' => 'Updated monitoring and review safeguards.',
        ])->assertCreated()->assertJsonPath('use_case.governance_status', 'reapproval_required');
        $this->assertSame('reapproval_required', $useCase->aiSystem->fresh()->governance_status);
    }

    public function test_monitoring_review_updates_due_date_and_opens_issue_when_action_is_needed(): void
    {
        $manager = $this->manager();
        $useCase = $this->approvedUseCase($manager);
        Sanctum::actingAs($manager);

        $this->postJson("/api/ai-use-cases/{$useCase->id}/monitoring-reviews", [
            'outcome' => 'needs_action',
            'performance_summary' => 'Hallucination rate exceeded the approved tolerance.',
            'incidents_count' => 1,
            'complaints_count' => 3,
            'evidence_reference' => 'AI-MONITOR-2026-Q3',
            'next_review_at' => now()->addMonth(),
        ])->assertCreated()
            ->assertJsonPath('data.ai_governance_decision_id', $useCase->decisions()->latest('id')->value('id'))
            ->assertJsonPath('data.assessment_version', 1)
            ->assertJsonPath('data.issue.status', 'open')
            ->assertJsonPath('use_case.governance_status', 'action_required');

        $this->assertDatabaseHas('ai_governance_issues', ['ai_use_case_id' => $useCase->id, 'owner_id' => $manager->id, 'status' => 'open']);
        $issue = AiGovernanceIssue::query()->where('ai_use_case_id', $useCase->id)->firstOrFail();
        $this->assertDatabaseHas('governance_issue_lifecycles', ['issue_type' => AiGovernanceIssue::class, 'issue_id' => $issue->id, 'status' => 'open']);

        $this->postJson("/api/ai-use-cases/{$useCase->id}/monitoring-reviews", [
            'outcome' => 'satisfactory', 'performance_summary' => 'A follow-up sample met tolerance, but the issue remains open.',
            'next_review_at' => now()->addMonths(2),
        ])->assertCreated()->assertJsonPath('use_case.governance_status', 'action_required');
    }

    public function test_decisions_are_append_only_through_models(): void
    {
        $manager = $this->manager();
        $useCase = $this->approvedUseCase($manager);
        $decision = $useCase->decisions()->firstOrFail();

        $this->expectException(\LogicException::class);
        $decision->update(['rationale' => 'Rewrite attempt.']);
    }

    public function test_assessments_are_append_only_through_models(): void
    {
        $manager = $this->manager();
        $useCase = $this->approvedUseCase($manager);

        $this->expectException(\LogicException::class);
        $useCase->assessments()->firstOrFail()->update(['assessment_summary' => 'Rewrite attempt.']);
    }

    public function test_monitoring_reviews_are_append_only_through_models(): void
    {
        $manager = $this->manager();
        $useCase = $this->approvedUseCase($manager);
        Sanctum::actingAs($manager);
        $this->postJson("/api/ai-use-cases/{$useCase->id}/monitoring-reviews", [
            'outcome' => 'satisfactory', 'performance_summary' => 'Approved controls continue to operate.',
            'next_review_at' => now()->addMonth(),
        ])->assertCreated();

        $this->expectException(\LogicException::class);
        $useCase->monitoringReviews()->firstOrFail()->delete();
    }

    public function test_expired_system_and_use_case_reviews_are_derived(): void
    {
        $manager = $this->manager();
        $system = AiSystem::factory()->create(['owner_id' => $manager->id, 'next_review_at' => now()->subDay()]);
        $useCase = $this->approvedUseCase($manager, ['ai_system_id' => $system->id, 'next_monitoring_at' => now()->subDay()]);

        $this->assertSame('review_overdue', $system->fresh()->governance_status);
        $this->assertSame('monitoring_overdue', $useCase->fresh()->governance_status);
    }

    public function test_owner_can_view_workspace_but_only_manager_can_change_governance(): void
    {
        $owner = User::factory()->create();
        $system = AiSystem::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($owner)->get(AiSystemResource::getUrl('index'))->assertOk();
        $this->get(AiSystemResource::getUrl('view', ['record' => $system]))->assertOk();
        $this->get(AiSystemResource::getUrl('edit', ['record' => $system]))->assertForbidden();
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('Manage AI Governance');

        return $user;
    }

    private function approvedUseCase(User $manager, array $attributes = []): AiUseCase
    {
        $useCase = AiUseCase::factory()->create(array_merge(['owner_id' => $manager->id], $attributes));
        AiRiskAssessment::factory()->create(['ai_use_case_id' => $useCase->id, 'assessor_id' => $manager->id]);
        $useCase->controls()->attach(Control::factory()->create());
        $useCase->risks()->attach(Risk::factory()->create());
        app(AiGovernanceManager::class)->decide($useCase, $manager, AiGovernanceDecisionType::Approved, [
            'rationale' => 'Residual risk is accepted with mapped controls.', 'conditions' => 'Continue human review.',
            'expires_at' => now()->addYear(), 'next_monitoring_at' => $attributes['next_monitoring_at'] ?? now()->addMonth(),
        ]);
        if (isset($attributes['next_monitoring_at'])) {
            $useCase->update(['next_monitoring_at' => $attributes['next_monitoring_at']]);
        }

        return $useCase;
    }
}
