<?php

namespace App\Console\Commands;

use App\PolicyCompliance\PolicyExceptionExpiryManager;
use Illuminate\Console\Command;

class ReconcilePolicyExceptions extends Command
{
    protected $signature = 'fynix:reconcile-policy-exceptions';

    protected $description = 'Expire governed policy exceptions after their configured expiration date';

    public function handle(PolicyExceptionExpiryManager $manager): int
    {
        $expired = $manager->reconcile();
        $this->info("Expired {$expired} governed policy exception(s).");

        return self::SUCCESS;
    }
}
