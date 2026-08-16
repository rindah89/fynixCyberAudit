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
        $schedule->command('fynix:authorization-audit-drain --once')->everyMinute()->withoutOverlapping();
        // Generate recurring checklists daily at 6:00 AM
        $schedule->command('checklists:generate-recurring')->dailyAt('06:00');
        $schedule->command('fynix:vendor-ledger-verify')
            ->hourly()
            ->withoutOverlapping()
            ->when(fn (): bool => (bool) config('suite.support.enabled'));
        $schedule->command('fynix:vendor-ledger-anchor')
            ->dailyAt('00:30')
            ->withoutOverlapping()
            ->when(fn (): bool => (bool) config('suite.support.anchor.enabled'));
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
