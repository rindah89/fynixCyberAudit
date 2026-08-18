<?php

namespace Tests\Feature;

use App\Models\VendorOperationEvent;
use App\Suite\VendorOperationLedger;
use App\Support\AuthorizationDenialAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AuthorizationDenialAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('authorization-audit.enabled', true);
        Config::set('authorization-audit.fingerprint_key', str_repeat('f', 32));
        Config::set('authorization-audit.spool', storage_path('framework/testing/authz-audit'));
        Config::set('suite.support.ledger_key', str_repeat('l', 32));
        Route::get('/_proof/denied/{record}', fn () => abort(403, 'denied'));
        Route::get('/_proof/unauthorized', fn () => response()->json(['error' => 'no'], 401));
    }

    public function test_exception_and_response_denials_are_recorded_once_with_bounded_metadata(): void
    {
        $this->get('/_proof/denied/private-customer-name?token=secret')->assertForbidden();
        $this->getJson('/_proof/unauthorized', ['Authorization' => 'Bearer raw-secret'])->assertUnauthorized();

        $this->assertDatabaseCount('vendor_operation_events', 2);
        $events = VendorOperationEvent::query()->orderBy('id')->get();
        $this->assertSame('/_proof/denied/{record}', $events[0]->metadata['route_template']);
        $this->assertSame(403, $events[0]->metadata['http_status']);
        $this->assertSame('anonymous', $events[0]->operator_subject);
        $this->assertStringStartsWith('machine:', $events[1]->operator_subject);
        $encoded = $events->toJson();
        $this->assertStringNotContainsString('private-customer-name', $encoded);
        $this->assertStringNotContainsString('raw-secret', $encoded);
        $this->assertStringNotContainsString('token', $encoded);
    }

    public function test_stale_spool_fails_readiness_health(): void
    {
        $directory = (string) config('authorization-audit.spool');
        mkdir($directory, 0700, true);
        $file = $directory.'/00000000-0000-4000-8000-000000001800.json';
        file_put_contents($file, '{}');
        touch($file, time() - 600);
        $health = app(AuthorizationDenialAudit::class)->health();
        $this->assertFalse($health['healthy']);
        unlink($file);
        rmdir($directory);
    }

    public function test_ledger_outage_spools_and_scheduled_drainer_replays_evidence(): void
    {
        Config::set('suite.support.ledger_key', 'short');
        $this->getJson('/_proof/unauthorized')->assertUnauthorized();

        $directory = (string) config('authorization-audit.spool');
        $files = glob($directory.'/*.json') ?: [];
        $this->assertCount(1, $files);
        $this->assertDatabaseCount('vendor_operation_events', 0);

        Config::set('suite.support.ledger_key', str_repeat('l', 32));
        $this->artisan('fynix:authorization-audit-drain --once')->assertSuccessful();
        $this->assertDatabaseCount('vendor_operation_events', 1);
        $this->assertSame([], glob($directory.'/*.json') ?: []);
        rmdir($directory);
    }

    public function test_drainer_acknowledges_a_delivery_already_committed_before_worker_interruption(): void
    {
        Config::set('suite.support.ledger_key', 'short');
        $this->getJson('/_proof/unauthorized')->assertUnauthorized();

        $directory = (string) config('authorization-audit.spool');
        $file = (glob($directory.'/*.json') ?: [])[0];
        $record = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        Config::set('suite.support.ledger_key', str_repeat('l', 32));
        app(VendorOperationLedger::class)->append($record['envelope'], $record['delivery_id']);

        $this->artisan('fynix:authorization-audit-drain --once')->assertSuccessful();
        $this->assertDatabaseCount('vendor_operation_events', 1);
        $this->assertFileDoesNotExist($file);
        rmdir($directory);
    }
}
