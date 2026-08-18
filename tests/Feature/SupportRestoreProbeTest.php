<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupportRestoreProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe_requires_dedicated_token_ability_and_audit_access(): void
    {
        Storage::fake('private');
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $operationId = '11111111-1111-4111-8111-111111111111';

        Sanctum::actingAs($user, ['support:restore-probe']);
        $this->postJson('/api/support/restore-probe', ['operation_id' => $operationId])
            ->assertForbidden();

        $user->givePermissionTo('List Audits');
        Sanctum::actingAs($user, ['audit:read']);
        $this->postJson('/api/support/restore-probe', ['operation_id' => $operationId])
            ->assertForbidden();
    }

    public function test_probe_reads_audits_and_removes_its_evidence_object(): void
    {
        Storage::fake('private');
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo('List Audits');
        Audit::factory()->create();
        $operationId = '22222222-2222-4222-8222-222222222222';

        Sanctum::actingAs($user, ['support:restore-probe']);
        $this->postJson('/api/support/restore-probe', ['operation_id' => $operationId])
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('audit_records', 1)
            ->assertJsonPath('evidence_storage', 'read-write')
            ->assertJsonPath('probe_cleanup', true);

        Storage::disk('private')->assertMissing('support-restore-probes/'.$operationId.'.probe');
    }
}
