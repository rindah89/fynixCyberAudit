<?php

namespace App\Console\Commands;

use App\PolicyCompliance\PolicyAcknowledgementEscalationManager;
use Illuminate\Console\Command;

class ReconcilePolicyAcknowledgementEscalations extends Command
{
    protected $signature = 'fynix:reconcile-policy-acknowledgement-escalations';

    protected $description = 'Escalate persistently overdue policy acknowledgements to current policy owners';

    public function handle(PolicyAcknowledgementEscalationManager $manager): int
    {
        $delivered = $manager->reconcile();
        $this->info("Delivered {$delivered} policy acknowledgement escalation(s).");

        return self::SUCCESS;
    }
}
