<?php

namespace Tests\Feature;

use App\Ai\StubAiClient;
use App\Enums\RiskStatus;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\RiskAssessmentItem;
use App\Models\User;
use App\RiskAssessor\RiskAssessor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RiskAssessorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.risk_assessor', true);
    }

    public function test_evaluate_fills_residual_without_writing_the_register(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Security Admin');

        $assessment = RiskAssessment::factory()->create([
            'owner_id' => $owner->id,
            'title' => 'Q3 residual review',
        ]);
        $item = RiskAssessmentItem::factory()->create([
            'risk_assessment_id' => $assessment->id,
            'name' => 'Ransomware on backups',
            'description' => 'Backup vault could be encrypted by ransomware.',
            'inherent_likelihood' => 4,
            'inherent_impact' => 5,
            'residual_likelihood' => null,
            'residual_impact' => null,
        ]);

        $client = new StubAiClient;
        $client->queue(json_encode([
            'residual_likelihood' => 2,
            'residual_impact' => 4,
            'treatment' => 'Mitigate',
            'justification' => 'Offline copies reduce likelihood.',
            'confidence' => 0.82,
        ]));
        $this->app->instance(StubAiClient::class, $client);

        $evaluated = app(RiskAssessor::class)->evaluate($owner, $item);

        $this->assertSame(2, $evaluated->residual_likelihood);
        $this->assertSame(4, $evaluated->residual_impact);
        $this->assertSame(8, $evaluated->residual_risk);
        $this->assertSame('Mitigate', $evaluated->treatment);
        $this->assertSame(0, Risk::query()->count());
    }

    public function test_promote_creates_a_risk_in_the_register(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Security Admin');

        $assessment = RiskAssessment::factory()->create([
            'owner_id' => $owner->id,
        ]);
        $item = RiskAssessmentItem::factory()->create([
            'risk_assessment_id' => $assessment->id,
            'name' => 'Vendor laptop loss',
            'description' => 'Unencrypted laptop lost in transit.',
            'inherent_likelihood' => 3,
            'inherent_impact' => 4,
            'residual_likelihood' => 2,
            'residual_impact' => 3,
            'residual_risk' => 6,
            'treatment' => 'Mitigate',
            'justification' => 'Disk encryption required.',
        ]);

        $risks = app(RiskAssessor::class)->promote($owner, $assessment, [$item->id]);

        $this->assertCount(1, $risks);
        $risk = $risks->first();
        $this->assertSame('Vendor laptop loss', $risk->name);
        $this->assertSame(2, $risk->residual_likelihood);
        $this->assertSame(3, $risk->residual_impact);
        $this->assertEquals(6, $risk->residual_risk);
        $this->assertSame(RiskStatus::ASSESSED, $risk->status);
        $this->assertSame($risk->id, $item->fresh()->risk_id);
    }

    public function test_non_collaborator_cannot_evaluate_or_promote(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Security Admin');
        $outsider = User::factory()->create();
        $outsider->assignRole('Security Admin');

        $assessment = RiskAssessment::factory()->create([
            'owner_id' => $owner->id,
        ]);
        $item = RiskAssessmentItem::factory()->create([
            'risk_assessment_id' => $assessment->id,
            'name' => 'Secret risk',
        ]);

        $client = new StubAiClient;
        $client->queue('{"residual_likelihood":1,"residual_impact":1,"treatment":"Accept","justification":"x","confidence":0.1}');
        $this->app->instance(StubAiClient::class, $client);

        try {
            app(RiskAssessor::class)->evaluate($outsider, $item);
            $this->fail('Expected non-collaborator to be refused');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        try {
            app(RiskAssessor::class)->promote($outsider, $assessment, [$item->id]);
            $this->fail('Expected non-collaborator promote to be refused');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, Risk::query()->count());
        $this->assertNull($item->fresh()->residual_likelihood);
    }
}
