<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class QueueController extends Controller
{
    /**
     * Check if a queue worker is currently running
     */
    public function isQueueWorkerRunning(): bool
    {
        if (config('queue.default') === 'sync') {
            return true;
        }

        $heartbeat = Cache::store(config('queue.heartbeat_store', 'database'))
            ->get(config('queue.heartbeat_key', 'queue:worker:heartbeat'));

        return is_numeric($heartbeat)
            && now()->timestamp - (int) $heartbeat <= (int) config('queue.heartbeat_ttl_seconds', 30);
    }

    /**
     * Start a queue worker in the background
     */
    public function startQueueWorker(): void
    {
        Log::warning('queue.worker_start_rejected', [
            'error_code' => 'WORKER_LIFECYCLE_EXTERNAL',
            'retryable' => false,
        ]);
    }

    /**
     * Ensure a queue worker is running, start one if needed
     * Returns true if worker was already running, false if a new one was started, null if auto-start is disabled
     */
    public function ensureQueueWorkerRunning(): ?bool
    {
        // Check if auto-start is enabled in configuration
        if (! config('queue.auto_start', false)) {
            Log::info('Queue auto-start is disabled, skipping worker check');

            return null; // Auto-start disabled
        }

        if (! $this->isQueueWorkerRunning()) {
            $this->startQueueWorker();

            return false; // Worker was not running, started a new one
        }

        return true; // Worker was already running
    }

    /**
     * Get queue worker status information
     */
    public function getQueueWorkerStatus(): array
    {
        $isRunning = $this->isQueueWorkerRunning();

        return [
            'is_running' => $isRunning,
            'status' => $isRunning ? 'running' : 'stopped',
            'checked_at' => now(),
        ];
    }

    /**
     * Stop all queue workers (if needed for maintenance)
     */
    public function stopQueueWorkers(): void
    {
        Log::warning('queue.worker_stop_rejected', [
            'error_code' => 'WORKER_LIFECYCLE_EXTERNAL',
            'retryable' => false,
        ]);
    }
}
