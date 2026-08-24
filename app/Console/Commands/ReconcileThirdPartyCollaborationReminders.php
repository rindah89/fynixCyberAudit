<?php

namespace App\Console\Commands;

use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationReminderManager;
use Illuminate\Console\Command;

class ReconcileThirdPartyCollaborationReminders extends Command
{
    protected $signature = 'fynix:reconcile-third-party-collaboration-reminders';

    protected $description = 'Deliver due-soon and overdue in-app reminders for governed provider collaboration requests';

    public function handle(ThirdPartyEngagementCollaborationReminderManager $manager): int
    {
        $this->info("Delivered {$manager->reconcile()} collaboration reminder(s).");

        return self::SUCCESS;
    }
}
