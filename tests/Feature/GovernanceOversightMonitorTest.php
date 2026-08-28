<?php

namespace Tests\Feature;

use App\Models\PrivacyRequest;
use App\Models\User;
use App\Notifications\DropdownNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GovernanceOversightMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('data_governance.required_sources', ['finance']);
        Config::set('data_governance.bindings.finance', [
            'enabled' => true,
            'tenant_id' => '11111111-1111-4111-8111-111111111111',
            'webhook_id' => '22222222-2222-4222-8222-222222222222',
            'secret' => 'governance-secret-that-is-long-enough',
        ]);
        Cache::forget('suite-governance-monitor:last-notified');
    }

    public function test_monitor_fails_when_evidence_is_missing_and_notifies_only_assurance_users(): void
    {
        Notification::fake();
        $auditor = User::factory()->create();
        $auditor->assignRole('Internal Auditor');
        $ordinaryUser = User::factory()->create();

        $this->artisan('fynix:monitor-governance --notify')
            ->expectsOutputToContain('attention_required')
            ->assertFailed();

        Notification::assertSentTo($auditor, DropdownNotification::class);
        Notification::assertNotSentTo($ordinaryUser, DropdownNotification::class);
    }

    public function test_unchanged_condition_is_deduplicated_for_one_day(): void
    {
        Notification::fake();
        $auditor = User::factory()->create();
        $auditor->assignRole('Internal Auditor');

        $this->artisan('fynix:monitor-governance --notify')->assertFailed();
        $this->artisan('fynix:monitor-governance --notify')->assertFailed();

        Notification::assertSentToTimes($auditor, DropdownNotification::class, 1);
    }

    public function test_operational_change_generates_a_new_oversight_notification(): void
    {
        Notification::fake();
        $auditor = User::factory()->create();
        $auditor->assignRole('Internal Auditor');

        $this->artisan('fynix:monitor-governance --notify')->assertFailed();
        PrivacyRequest::create([
            'tenant_id' => '11111111-1111-4111-8111-111111111111',
            'source' => 'finance',
            'subject_ref' => '10000000-0000-4000-8000-000000000001',
            'right' => 'access',
            'lawful_basis' => 'data_subject_request',
            'requested_at' => now()->subDays(31),
            'due_at' => now()->subDay(),
            'status' => 'open',
        ]);

        $this->artisan('fynix:monitor-governance --notify')
            ->expectsOutputToContain('1-privacy-overdue')
            ->assertFailed();

        Notification::assertSentToTimes($auditor, DropdownNotification::class, 2);
    }
}
