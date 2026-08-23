<?php

namespace Tests\Feature;

use App\Enums\RiskDomain;
use App\Models\Risk;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RiskDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_create_an_enterprise_risk_through_the_rest_interface(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $operator = User::factory()->create();
        $operator->givePermissionTo('Create Risks');
        Sanctum::actingAs($operator);

        $this->postJson('/api/risks', [
            'code' => 'ERM-001',
            'name' => 'Strategic concentration risk',
            'description' => 'Critical revenue depends on a single market.',
            'domain' => RiskDomain::Enterprise->value,
            'status' => 'Not Assessed',
            'inherent_likelihood' => 4,
            'inherent_impact' => 5,
            'residual_likelihood' => 3,
            'residual_impact' => 4,
        ])->assertCreated()
            ->assertJsonPath('data.domain', RiskDomain::Enterprise->value);

        $this->assertDatabaseHas('risks', [
            'code' => 'ERM-001',
            'domain' => RiskDomain::Enterprise->value,
            'inherent_risk' => 20,
            'residual_risk' => 12,
        ]);
    }

    public function test_rest_interface_rejects_an_unsupported_risk_domain(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $operator = User::factory()->create();
        $operator->givePermissionTo('Create Risks');
        Sanctum::actingAs($operator);

        $this->postJson('/api/risks', [
            'code' => 'RISK-INVALID',
            'name' => 'Unclassified risk',
            'domain' => 'financial',
            'inherent_likelihood' => 3,
            'inherent_impact' => 3,
            'residual_likelihood' => 2,
            'residual_impact' => 2,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('domain');

        $this->assertDatabaseMissing('risks', ['code' => 'RISK-INVALID']);
    }

    public function test_non_rest_writes_apply_scoring_defaults_without_inventing_a_domain(): void
    {
        $risk = Risk::create([
            'code' => 'RISK-LEGACY',
            'name' => 'Needs classification',
        ]);

        $this->assertNull($risk->domain);
        $this->assertSame(3, $risk->inherent_likelihood);
        $this->assertSame(3, $risk->inherent_impact);
        $this->assertSame(9, $risk->inherent_risk);
        $this->assertSame(9, $risk->residual_risk);
    }
}
