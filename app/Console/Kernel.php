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
        $schedule->command('fynix:monitor-governance --notify')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('fynix:publish-governance')
            ->dailyAt('02:45')
            ->withoutOverlapping()
            ->onOneServer();
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
