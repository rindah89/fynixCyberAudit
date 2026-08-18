<?php

namespace App\Console\Commands;

use App\Support\AuthorizationDenialAudit;
use Illuminate\Console\Command;

final class DrainAuthorizationDenialAudit extends Command
{
    protected $signature = 'fynix:authorization-audit-drain {--once}';

    protected $description = 'Replay durable CyberAudit authorization-denial evidence into the vendor ledger';

    public function handle(AuthorizationDenialAudit $audit): int
    {
        $this->info('Drained '.$audit->drain().' authorization denial records.');

        return self::SUCCESS;
    }
}
