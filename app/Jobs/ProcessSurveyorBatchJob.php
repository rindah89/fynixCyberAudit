<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Surveyor\Surveyor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSurveyorBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $jobId) {}

    public function handle(Surveyor $surveyor): void
    {
        $job = AiJob::query()->findOrFail($this->jobId);

        if ($surveyor->processNext($job)) {
            self::dispatch($this->jobId);
        }
    }
}
