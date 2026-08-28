<?php

namespace Tests\Feature;

use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CyberAuditPrivacyExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_exports_only_registered_relationships_with_reviewable_evidence(): void
    {
        Config::set('data_governance.publisher.tenant_id', 'tenant-1');
        $user = User::factory()->create(['name' => 'Privacy User', 'email' => 'privacy@example.test']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/governance/privacy/access-export')->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('privacy@example.test', $response->json('account.email'));
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $response->json('subject_ref'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response->json('evidence_sha256'));
        $this->assertArrayNotHasKey('password', $response->json('account'));
        $this->assertContains('governance_control_reviews', $response->json('relationship_manifest'));

        $privacy = PrivacyRequest::query()->sole();
        $this->assertSame('closed', $privacy->status);
        $this->assertSame('pending_review', $privacy->review_status);
        $this->assertSame($response->json('evidence_sha256'), $privacy->evidence_sha256);
    }

    public function test_export_requires_an_authenticated_user(): void
    {
        $this->postJson('/api/governance/privacy/access-export')->assertUnauthorized();
    }
}
