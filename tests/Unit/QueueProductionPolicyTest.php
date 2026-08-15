<?php

namespace Tests\Unit;

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
}
