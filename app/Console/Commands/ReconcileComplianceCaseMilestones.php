<?php

namespace App\Console\Commands;

use App\ComplianceCases\ComplianceCaseMilestoneManager;
use Illuminate\Console\Command;

class ReconcileComplianceCaseMilestones extends Command
{
    protected $signature = 'fynix:reconcile-compliance-case-milestones';

    protected $description = 'Retain idempotent due-soon and overdue evidence for open compliance-case milestones';

    public function handle(ComplianceCaseMilestoneManager $manager): int
    {
        $delivered = $manager->reconcile();
        $this->info("Retained {$delivered} compliance-case milestone due-state event(s).");

        return self::SUCCESS;
    }
}
