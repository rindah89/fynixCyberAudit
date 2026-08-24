<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Generate recurring checklists daily at 6:00 AM
        $schedule->command('checklists:generate-recurring')->dailyAt('06:00');
        $schedule->command('fynix:reconcile-policy-exceptions')->dailyAt('00:10')->withoutOverlapping();
        $schedule->command('fynix:reconcile-policy-acknowledgement-reminders')->dailyAt('08:00')->withoutOverlapping();
        $schedule->command('fynix:reconcile-policy-acknowledgement-escalations')->dailyAt('08:10')->withoutOverlapping();
        $schedule->command('fynix:reconcile-third-party-collaboration-reminders')->dailyAt('08:20')->withoutOverlapping();
        $schedule->command('fynix:reconcile-third-party-collaboration-escalations')->dailyAt('08:30')->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
