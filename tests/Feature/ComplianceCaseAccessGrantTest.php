<?php

namespace Tests\Feature;

use App\ComplianceCases\ComplianceCaseAccessGrantManager;
use App\ComplianceCases\ComplianceCaseManager;
use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCasePriority;
use App\Models\ComplianceCaseAccessGrant;
use App\Models\User;
use App\Support\CanonicalJson;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ComplianceCaseAccessGrantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.compliance_cases', true);
    }

    public function test_need_to_know_grant_confers_case_read_only_until_revoked_or_expired(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $reader = User::factory()->create();
        $cases = app(ComplianceCaseManager::class);
        $case = $cases->open($manager, [
            'title' => 'Sensitive case', 'category' => ComplianceCaseCategory::Fraud->value,
            'priority' => ComplianceCasePriority::High->value, 'allegation' => 'A governed allegation.',
            'summary' => 'Open.',
        ]);

        $this->actingAs($reader)->getJson("/api/compliance-cases/{$case->id}")->assertForbidden();
        $this->actingAs($reader)->getJson('/api/compliance-cases')->assertForbidden();

        $this->actingAs($manager)->postJson("/api/compliance-cases/{$case->id}/access-grants", [
            'grantee_id' => $reader->id,
            'purpose' => 'Server-owned grant fields are prohibited.',
            'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
            'fingerprint' => str_repeat('a', 64),
            'version' => 9,
        ])->assertUnprocessable();

        $grantId = $this->actingAs($manager)->postJson("/api/compliance-cases/{$case->id}/access-grants", [
            'grantee_id' => $reader->id,
            'purpose' => 'Legal overlay review of the current case record.',
            'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
        ])->assertCreated()->json('data.id');
        $grant = ComplianceCaseAccessGrant::query()->findOrFail($grantId);
        $this->assertSame(
            hash('sha256', CanonicalJson::encode(app(ComplianceCaseAccessGrantManager::class)->payload($grant))),
            $grant->fingerprint,
        );

        $this->actingAs($reader)->getJson("/api/compliance-cases/{$case->id}")
            ->assertOk()->assertJsonPath('data.id', $case->id);
        $this->actingAs($reader)->getJson('/api/compliance-cases')->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($reader)->postJson("/api/compliance-cases/{$case->id}/events", [
            'summary' => 'Grants do not confer mutation.',
        ])->assertForbidden();
        $this->actingAs($reader)->postJson("/api/compliance-cases/{$case->id}/conflicts", [
            'subject_user_id' => $reader->id,
            'nature' => 'Grant readers cannot mutate the conflict register.',
            'rationale' => 'Need-to-know grants are read only.',
        ])->assertForbidden();

        $this->actingAs($manager)->postJson("/api/compliance-case-access-grants/{$grantId}/revoke", [
            'summary' => 'Access is no longer required.',
        ])->assertCreated();
        $this->actingAs($reader)->getJson("/api/compliance-cases/{$case->id}")->assertForbidden();

        $expiredId = $this->actingAs($manager)->postJson("/api/compliance-cases/{$case->id}/access-grants", [
            'grantee_id' => $reader->id,
            'purpose' => 'Expired overlay.',
            'starts_at' => now()->subHours(2)->toIso8601String(),
            'ends_at' => now()->subHour()->toIso8601String(),
        ])->assertCreated()->json('data.id');
        $this->actingAs($reader)->getJson("/api/compliance-cases/{$case->id}")->assertForbidden();
        $this->assertNotNull($expiredId);
    }

    public function test_recused_manager_cannot_revoke_a_grant(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Security Admin');
        $reviewer = User::factory()->create();
        $reviewer->assignRole('Security Admin');
        $reader = User::factory()->create();
        $case = app(ComplianceCaseManager::class)->open($manager, [
            'title' => 'Grant recusal', 'category' => ComplianceCaseCategory::Fraud->value,
            'priority' => ComplianceCasePriority::High->value, 'allegation' => 'A governed allegation.',
            'summary' => 'Open.',
        ]);
        $grantId = $this->actingAs($manager)->postJson("/api/compliance-cases/{$case->id}/access-grants", [
            'grantee_id' => $reader->id,
            'purpose' => 'Overlay review.',
            'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
        ])->assertCreated()->json('data.id');
        $declaration = $this->actingAs($manager)->postJson("/api/compliance-cases/{$case->id}/conflicts", [
            'subject_user_id' => $manager->id, 'nature' => 'Manager conflict.', 'rationale' => 'Recuse the grantor.',
        ])->assertCreated()->json('data.id');
        $this->actingAs($reviewer)->postJson("/api/compliance-case-conflicts/{$declaration}/decision", [
            'decision' => 'confirmed', 'summary' => 'Grantor is recused.',
        ])->assertCreated();
        $this->actingAs($manager)->postJson("/api/compliance-case-access-grants/{$grantId}/revoke", [
            'summary' => 'Recused manager must not revoke.',
        ])->assertForbidden();
    }

    public function test_canonical_grant_factory_reconstructs_production_fingerprint(): void
    {
        $grant = ComplianceCaseAccessGrant::factory()->create();
        $this->assertSame(
            hash('sha256', CanonicalJson::encode(app(ComplianceCaseAccessGrantManager::class)->payload($grant))),
            $grant->fingerprint,
        );
        $this->assertTrue($grant->isActiveAt());
    }
}
