<?php

namespace Tests\Feature;

use App\Suite\SuiteEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class ItsmSuiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('suite.itsm.enabled', true);
        foreach (['company_id', 'origin_id', 'ticket_type_id', 'department_id', 'priority_id', 'sync_analyst_id'] as $key) {
            Config::set('suite.itsm.'.$key, 1);
        }
        Config::set('suite.itsm.base_url', 'https://itsm.test');
        Config::set('suite.itsm.public_url', 'https://itsm.test');
        Config::set('suite.itsm.grc_public_url', 'https://grc.test');
        Config::set('suite.itsm.token', 'fitsm_'.str_repeat('a', 48));
        Config::set('suite.itsm.requester_email', 'grc-integration@example.invalid');
        Config::set('suite.itsm.webhook_id', '22222222-2222-4222-8222-222222222222');
        Config::set('suite.itsm.webhook_secret', 'itsm-secret');
        Config::set('suite.itsm.local_tenant_id', '11111111-1111-4111-8111-111111111111');
        Config::set('suite.itsm.remote_tenant_id', '33333333-3333-4333-8333-333333333333');
    }

    public function test_preflight_accepts_complete_configuration(): void
    {
        $this->artisan('fynix:suite-preflight')->assertSuccessful();
    }

    public function test_signed_unknown_itsm_event_is_safely_ignored(): void
    {
        $event = 'itsm.lms.course_completed';
        $body = json_encode(['event_type' => $event, 'tenant_id' => config('suite.itsm.remote_tenant_id'), 'entity_type' => 'course', 'entity_id' => (string) Str::uuid(), 'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'payload' => []], JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $delivery = (string) Str::uuid();
        $webhook = config('suite.itsm.webhook_id');
        $signature = SuiteEnvelope::sign('itsm-secret', $timestamp, $event, 'itsm', $webhook, $delivery, $body);

        $this->call('POST', '/api/suite/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_FYNIX_SIGNATURE' => $signature, 'HTTP_X_FYNIX_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_FYNIX_EVENT' => $event, 'HTTP_X_FYNIX_SOURCE' => 'itsm',
            'HTTP_X_FYNIX_WEBHOOK_ID' => $webhook, 'HTTP_X_FYNIX_DELIVERY_ID' => $delivery,
        ], $body)->assertOk()->assertJsonPath('outcome', 'ignored');
    }
}
