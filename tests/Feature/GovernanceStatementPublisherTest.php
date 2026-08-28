<?php

namespace Tests\Feature;

use App\Suite\GovernanceStatementPublisher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GovernanceStatementPublisherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('data_governance.publisher', [
            'endpoint' => 'https://cyberaudit.example/api/suite/governance/evidence',
            'tenant_id' => 'tenant-1',
            'webhook_id' => '11111111-1111-4111-8111-111111111111',
            'secret' => str_repeat('x', 32),
        ]);
    }

    public function test_statement_is_complete_and_does_not_hide_known_gaps(): void
    {
        $statement = app(GovernanceStatementPublisher::class)->build(new \DateTimeImmutable('2026-08-28T12:00:00+00:00'));
        $controls = $statement['payload']['controls'];
        $this->assertCount(12, $controls);
        $this->assertCount(12, array_unique(array_column($controls, 'control_id')));
        $this->assertSame('partially_effective', collect($controls)->firstWhere('control_id', 'DG-03')['status']);
    }

    public function test_command_signs_statement_and_validates_receipt(): void
    {
        Http::fake(function ($request) {
            $body = $request->data();
            $this->assertSame('cyberaudit', $request->header('X-Fynix-Source')[0]);
            $this->assertStringStartsWith('v2=', $request->header('X-Fynix-Signature')[0]);
            $this->assertSame('tenant-1', $body['tenant_id']);

            return Http::response(['outcome' => 'recorded', 'statement_id' => $body['entity_id']], 201);
        });

        $this->artisan('fynix:publish-governance')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_publisher_rejects_insecure_remote_endpoint(): void
    {
        Config::set('data_governance.publisher.endpoint', 'http://cyberaudit.example/evidence');
        $this->expectException(\InvalidArgumentException::class);
        app(GovernanceStatementPublisher::class)->publish();
    }
}
