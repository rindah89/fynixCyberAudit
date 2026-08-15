<?php

namespace Tests\Unit;

use App\Http\Controllers\QueueController;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class QueueProductionPolicyTest extends TestCase
{
    public function test_durable_queue_adapters_dispatch_after_commit(): void
    {
        $this->assertTrue(config('queue.connections.database.after_commit'));
        $this->assertTrue(config('queue.connections.redis.after_commit'));
    }

    public function test_web_requests_cannot_auto_start_workers(): void
    {
        $this->assertFalse(config('queue.auto_start'));
    }

    public function test_worker_status_requires_a_fresh_external_heartbeat(): void
    {
        config()->set('queue.default', 'database');
        config()->set('queue.heartbeat_store', 'array');
        config()->set('queue.heartbeat_ttl_seconds', 30);

        $controller = new QueueController;
        $this->assertFalse($controller->isQueueWorkerRunning());

        Cache::store('array')->put('queue:worker:heartbeat', now()->timestamp, 60);
        $this->assertTrue($controller->isQueueWorkerRunning());

        Cache::store('array')->put('queue:worker:heartbeat', now()->subMinute()->timestamp, 60);
        $this->assertFalse($controller->isQueueWorkerRunning());
    }

    public function test_retry_lease_exceeds_worker_timeout(): void
    {
        $this->assertGreaterThan(300, config('queue.connections.database.retry_after'));
        $this->assertGreaterThan(300, config('queue.connections.redis.retry_after'));
    }
}
