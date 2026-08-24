<?php

namespace App\Console\Commands;

use App\PolicyCompliance\PolicyAcknowledgementReminderManager;
use Illuminate\Console\Command;

class ReconcilePolicyAcknowledgementReminders extends Command
{
    protected $signature = 'fynix:reconcile-policy-acknowledgement-reminders';

    protected $description = 'Deliver due-soon and overdue policy acknowledgement reminders';

    public function handle(PolicyAcknowledgementReminderManager $manager): int
    {
        $delivered = $manager->reconcile();
        $this->info("Delivered {$delivered} policy acknowledgement reminder(s).");

        return self::SUCCESS;
    }
}
