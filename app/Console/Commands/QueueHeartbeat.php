<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class QueueHeartbeat extends Command
{
    protected $signature = 'queue:heartbeat {--watch : Continue publishing heartbeats}';

    protected $description = 'Publish the supervised queue worker heartbeat';

    public function handle(): int
    {
        do {
            Cache::store(config('queue.heartbeat_store', 'database'))->put(
                config('queue.heartbeat_key', 'queue:worker:heartbeat'),
                now()->timestamp,
                max(60, (int) config('queue.heartbeat_ttl_seconds', 30) * 2),
            );

            if (! $this->option('watch')) {
                break;
            }

            sleep(max(1, (int) config('queue.heartbeat_interval_seconds', 10)));
        } while (true);

        return self::SUCCESS;
    }
}
