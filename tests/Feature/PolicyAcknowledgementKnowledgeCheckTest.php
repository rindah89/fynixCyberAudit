<?php

namespace Tests\Feature;

use App\Filament\Exports\PolicyAcknowledgementAssignmentExporter;
use App\Filament\Resources\PolicyAcknowledgementResource\Pages\ViewPolicyAcknowledgement;
use App\Models\Policy;
use App\Models\User;
use App\PolicyCompliance\PolicyAcknowledgementManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class PolicyAcknowledgementKnowledgeCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_employee_must_pass_server_scored_check_before_acknowledgement(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('Update Policies');
        $employee = User::factory()->create();
        $policy = Policy::factory()->create([
            'owner_id' => $manager->id, 'effective_date' => today()->subDay(), 'retired_date' => null,
            'body' => '<p>Never share credentials.</p>',
        ]);
        $campaign = app(PolicyAcknowledgementManager::class)->launch($policy, $manager, [
            'title' => 'Security comprehension', 'due_at' => now()->addWeek(),
            'audience_user_ids' => [$employee->id],
            'knowledge_check' => [
                'passing_percentage' => 100, 'max_attempts' => 3,
                'questions' => [
                    ['code' => 'credentials', 'prompt' => 'May credentials be shared?', 'options' => ['Yes', 'No'], 'correct_option' => 1],
                    ['code' => 'reporting', 'prompt' => 'Report suspected compromise?', 'options' => ['Immediately', 'Never'], 'correct_option' => 0],
                ],
            ],
        ]);
        $assignment = $campaign->assignments()->firstOrFail();
        $this->assertNotNull($campaign->knowledge_check_fingerprint);
        $this->assertArrayNotHasKey('correct_option', $campaign->knowledge_check['questions'][0]);
        $this->actingAs($employee, 'web');
        Livewire::test(ViewPolicyAcknowledgement::class, ['record' => $assignment->id])
            ->assertActionVisible('knowledge_check')->assertActionHidden('acknowledge');

        Sanctum::actingAs($employee);
        $this->postJson("/api/policy-acknowledgement-assignments/{$assignment->id}/acknowledge", ['acknowledged' => true])
            ->assertUnprocessable();
        $failed = $this->postJson("/api/policy-acknowledgement-assignments/{$assignment->id}/knowledge-check-attempts", [
            'answers' => ['credentials' => 0, 'reporting' => 0],
        ])->assertCreated()->json('data');
        $this->assertFalse($failed['passed']);
        $this->assertSame(50, $failed['score_percentage']);
        $this->assertArrayNotHasKey('question_snapshot', $failed);

        $passed = $this->postJson("/api/policy-acknowledgement-assignments/{$assignment->id}/knowledge-check-attempts", [
            'answers' => ['credentials' => 1, 'reporting' => 0],
        ])->assertCreated()->json('data');
        $this->assertTrue($passed['passed']);
        $this->assertSame(2, $passed['version']);
        $this->postJson("/api/policy-acknowledgement-assignments/{$assignment->id}/acknowledge", ['acknowledged' => true])
            ->assertCreated();

        $attempt = $assignment->knowledgeCheckAttempts()->latest('version')->firstOrFail();
        $payload = [
            'policy_acknowledgement_assignment_id' => $assignment->id,
            'policy_acknowledgement_campaign_id' => $campaign->id,
            'version' => 2, 'submitted_by' => $employee->id,
            'answers_snapshot' => $attempt->answers_snapshot,
            'question_snapshot' => $attempt->question_snapshot,
            'score_percentage' => 100, 'passed' => true,
            'submitted_at' => $attempt->submitted_at->toISOString(),
        ];
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $attempt->fingerprint);

        $this->getJson('/api/policy-acknowledgements/mine')->assertOk()
            ->assertJsonCount(2, 'data.0.knowledge_check_attempts')
            ->assertJsonMissing(['correct_option' => 1]);
        Sanctum::actingAs($manager);
        $this->getJson("/api/policy-acknowledgement-campaigns/{$campaign->id}/report")
            ->assertOk()->assertJsonCount(2, 'data.0.knowledge_check_attempts')
            ->assertJsonPath('data.0.knowledge_check_attempts.1.question_snapshot.questions.0.correct_option', 1);
        $this->assertContains('knowledge_check_attempt_history', collect(PolicyAcknowledgementAssignmentExporter::getColumns())->map->getName());
    }

    public function test_check_enforces_owner_attempt_and_answer_bounds(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('Update Policies');
        $employee = User::factory()->create();
        $outsider = User::factory()->create();
        $policy = Policy::factory()->create([
            'owner_id' => $manager->id, 'effective_date' => today()->subDay(), 'retired_date' => null,
            'body' => '<p>Policy.</p>',
        ]);
        $campaign = app(PolicyAcknowledgementManager::class)->launch($policy, $manager, [
            'title' => 'Bounded check', 'due_at' => now()->addWeek(), 'audience_user_ids' => [$employee->id],
            'knowledge_check' => ['passing_percentage' => 100, 'max_attempts' => 1, 'questions' => [
                ['code' => 'q1', 'prompt' => 'Choose A.', 'options' => ['A', 'B'], 'correct_option' => 0],
            ]],
        ]);
        $assignment = $campaign->assignments()->firstOrFail();
        Sanctum::actingAs($outsider);
        $this->postJson("/api/policy-acknowledgement-assignments/{$assignment->id}/knowledge-check-attempts", ['answers' => ['q1' => 0]])->assertForbidden();
        Sanctum::actingAs($employee);
        $this->postJson("/api/policy-acknowledgement-assignments/{$assignment->id}/knowledge-check-attempts", ['answers' => ['wrong' => 0]])->assertUnprocessable();
        $this->postJson("/api/policy-acknowledgement-assignments/{$assignment->id}/knowledge-check-attempts", ['answers' => ['q1' => 1]])->assertCreated();
        $this->postJson("/api/policy-acknowledgement-assignments/{$assignment->id}/knowledge-check-attempts", ['answers' => ['q1' => 0]])->assertUnprocessable();
        $this->assertDatabaseCount('policy_acknowledgement_knowledge_check_attempts', 1);
        $attempt = $assignment->knowledgeCheckAttempts()->firstOrFail();
        try {
            $attempt->update(['score_percentage' => 100]);
            $this->fail('Comprehension-check evidence was mutable.');
        } catch (\LogicException) {
            $this->assertSame(0, $attempt->fresh()->score_percentage);
        }
        $migration = require database_path('migrations/2026_08_24_550000_create_policy_acknowledgement_knowledge_checks.php');
        $migration->down();
        $this->assertDatabaseHas('policy_acknowledgement_knowledge_check_attempts', ['id' => $attempt->id, 'fingerprint' => $attempt->fingerprint]);
    }
}
