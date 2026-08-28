<?php

namespace Tests\Feature;

use App\Models\PrivacyRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CyberAuditPrivacyRightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('data_governance.publisher.tenant_id', 'tenant-1');
    }

    public function test_verified_user_can_correct_restrict_and_object_with_digest_bound_evidence(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);
        Sanctum::actingAs($user);

        $this->postJson('/api/governance/privacy/rights', $this->payload('correction', ['name' => 'Correct Name']))
            ->assertOk()->assertJsonPath('source_action_completed', true);
        $this->assertSame('Correct Name', $user->fresh()->name);

        $this->postJson('/api/governance/privacy/rights', $this->payload('restriction'))->assertOk();
        $this->assertNotNull($user->fresh()->privacy_restricted_at);
        $this->getJson('/api/user')->assertStatus(423)->assertJsonPath('code', 'privacy_processing_restricted');

        $this->postJson('/api/governance/privacy/rights', $this->payload('objection'))->assertOk();
        $this->assertNotNull($user->fresh()->processing_objection_at);
        $this->assertSame(3, PrivacyRequest::query()->where('status', 'closed')->where('review_status', 'pending_review')->count());
        $this->assertTrue(PrivacyRequest::query()->get()->every(fn (PrivacyRequest $request): bool => strlen((string) $request->evidence_sha256) === 64));
    }

    public function test_verified_deletion_anonymizes_account_revokes_access_and_preserves_opaque_evidence(): void
    {
        $user = User::factory()->create(['name' => 'Private Person', 'email' => 'private@example.test']);
        $user->assignRole('Internal Auditor');
        $token = $user->createToken('privacy-test');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/governance/privacy/rights', $this->payload('deletion'))
            ->assertOk()->assertJsonPath('right', 'deletion');

        $erased = User::withTrashed()->findOrFail($user->id);
        $this->assertTrue($erased->trashed());
        $this->assertSame('Erased user', $erased->name);
        $this->assertStringEndsWith('@invalid.fynix', $erased->email);
        $this->assertNotSame('private@example.test', $erased->email);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseMissing('model_has_roles', ['model_type' => User::class, 'model_id' => $user->id]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response->json('evidence_sha256'));
    }

    public function test_privileged_emergency_account_cannot_self_anonymize(): void
    {
        $user = User::factory()->create(['is_break_glass' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/api/governance/privacy/rights', $this->payload('deletion'))->assertUnprocessable();
        $this->assertNull($user->fresh()->deleted_at);
        $this->assertDatabaseCount('privacy_requests', 0);
    }

    /** @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function payload(string $right, array $extra = []): array
    {
        return ['right' => $right, 'identity_verification_ref' => 'evidence://privacy/identity/current-user', ...$extra];
    }
}
