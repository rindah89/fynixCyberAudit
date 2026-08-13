<?php

namespace App\Jobs;

use App\AiAudit\PerformAiAudit;
use App\Models\Audit;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PerformAiAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $auditId,
        public int $actorId,
    ) {}

    public function handle(PerformAiAudit $perform): void
    {
        $audit = Audit::query()->with('auditItems.auditable')->findOrFail($this->auditId);
        $actor = User::query()->findOrFail($this->actorId);

        $perform($actor, $audit);
    }
}
