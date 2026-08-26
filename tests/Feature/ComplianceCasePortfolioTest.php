<?php

namespace Tests\Feature;

use App\ComplianceCases\ComplianceCaseManager;
use App\ComplianceCases\ComplianceCasePortfolioManager;
use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCasePriority;
use App\Enums\ComplianceCaseStatus;
use App\Filament\Pages\ComplianceCasePortfolio;
use App\Models\ComplianceCase;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ComplianceCasePortfolioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.compliance_cases', true);
    }

    public function test_portfolio_aggregates_only_cases_the_caller_can_view(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00', 'UTC'));
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $grantee = User::factory()->create();
        $investigator = User::factory()->create();
        $investigator->givePermissionTo('Investigate Compliance Cases');
        $stranger = User::factory()->create();
        $cases = app(ComplianceCaseManager::class);
        $visible = $cases->open($manager, [
            'title' => 'Visible case', 'category' => ComplianceCaseCategory::Other->value,
            'priority' => ComplianceCasePriority::Low->value, 'allegation' => 'Secret allegation one.',
            'summary' => 'Open visible.',
        ]);
        ComplianceCase::factory()->create([
            'status' => ComplianceCaseStatus::Closed, 'opened_by' => $manager->id,
            'opened_at' => Carbon::parse('2026-01-01 00:00:00', 'UTC'),
            'closed_at' => Carbon::parse('2026-01-15 00:00:00', 'UTC'),
        ]);

        $hidden = $cases->open($manager, [
            'title' => 'Hidden case', 'category' => ComplianceCaseCategory::Fraud->value,
            'priority' => ComplianceCasePriority::High->value, 'allegation' => 'Secret allegation two.',
            'summary' => 'Open hidden.',
        ]);
        $this->actingAs($manager)->postJson("/api/compliance-cases/{$hidden->id}/access-grants", [
            'grantee_id' => $grantee->id, 'purpose' => 'Overlay review.',
            'starts_at' => now()->subMinute()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String(),
        ])->assertCreated();
        $this->actingAs($manager)->postJson("/api/compliance-cases/{$visible->id}/milestones", [
            'title' => 'Overdue pack', 'description' => 'Due before now.', 'owner_id' => $manager->id,
            'due_at' => now()->subHour()->toIso8601String(), 'required' => false,
        ])->assertCreated();

        $this->actingAs($stranger)->getJson('/api/compliance-case-portfolio')->assertForbidden();
        try {
            app(ComplianceCasePortfolioManager::class)->summarize($stranger);
            $this->fail('Direct portfolio aggregation must not return zeros for callers with no case visibility.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $empty = $this->actingAs($investigator)->getJson('/api/compliance-case-portfolio')->assertOk()->json('data');
        $this->assertSame(0, $empty['total']);
        $this->assertSame(0, $empty['overdue_milestones']);
        $this->assertStringNotContainsString('Secret allegation', json_encode($empty));

        $managerView = $this->actingAs($manager)->getJson('/api/compliance-case-portfolio')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(3, $managerView['total']);
        $this->assertArrayNotHasKey('allegation', $managerView);
        $this->assertArrayHasKey('by_status', $managerView);
        $this->assertArrayHasKey('age_bands', $managerView);
        $this->assertArrayHasKey('by_phase', $managerView);
        $this->assertGreaterThanOrEqual(2, $managerView['by_phase']['intake']);
        $this->assertSame(1, $managerView['overdue_milestones']);
        $this->assertSame(1, $managerView['closed']);
        $this->assertGreaterThanOrEqual(1, $managerView['age_bands']['0_7']);

        $filtered = $this->actingAs($manager)->getJson('/api/compliance-case-portfolio?opened_from=2026-08-01&opened_to=2026-08-25')
            ->assertOk()->json('data');
        $this->assertSame(2, $filtered['total']);
        $this->assertSame(0, $filtered['closed']);
        $this->actingAs($manager)->getJson('/api/compliance-case-portfolio?opened_from=2025-01-01&opened_to=2026-08-25')
            ->assertUnprocessable();

        $csv = $this->actingAs($manager)->get('/api/compliance-case-portfolio?format=csv')->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
        $this->assertStringContainsString('overdue_milestones', $csv->getContent());
        $this->assertStringContainsString('by_phase.intake', $csv->getContent());
        $this->assertStringNotContainsString('Secret allegation', $csv->getContent());

        $grantedView = $this->actingAs($grantee)->getJson('/api/compliance-case-portfolio')->assertOk()->json('data');
        $this->assertSame(1, $grantedView['total']);
        $this->assertSame(1, $grantedView['by_status']['New'] ?? $grantedView['by_status']['new'] ?? 0);
        $this->assertSame(0, $grantedView['overdue_milestones']);
        $this->assertStringNotContainsString('Secret allegation', json_encode($grantedView));
        Livewire::actingAs($manager)->test(ComplianceCasePortfolio::class)
            ->assertSee('Opened from')
            ->assertSee('Opened to')
            ->assertSee('By phase')
            ->assertSee('Review decision')
            ->assertDontSee('Secret allegation');
        Carbon::setTestNow();
    }
}
