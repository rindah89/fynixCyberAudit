<?php

namespace App\Console\Commands;

use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationEscalationManager;
use Illuminate\Console\Command;

class ReconcileThirdPartyCollaborationEscalations extends Command
{
    protected $signature = 'fynix:reconcile-third-party-collaboration-escalations';

    protected $description = 'Escalate persistently overdue governed provider collaboration requests';

    public function handle(ThirdPartyEngagementCollaborationEscalationManager $manager): int
    {
        $this->info("Delivered {$manager->reconcile()} collaboration escalation(s).");

        return self::SUCCESS;
    }
}
