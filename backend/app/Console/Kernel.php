<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->call(function (): void {
            $yearMonth = Carbon::now()->subMonthNoOverflow()->format('Y-m');
            Artisan::call('dataset-records:ingest', ['ym' => $yearMonth]);
        })->monthlyOn(1, '00:00')->name('dataset-records:ingest previous month');

        // Queue health monitoring
        $schedule->job(new \App\Jobs\QueueHealthCheck())->everyFiveMinutes();
        $schedule->command('metrics:export-queue')->everyFiveMinutes();

        // Prune old jobs
        $schedule->command('queue:prune-failed --hours=168')->daily(); // Keep 7 days
        $schedule->command('queue:prune-batches --hours=168')->daily();
        $schedule->command('model:prune')->daily();

        // Horizon snapshots
        $schedule->command('horizon:snapshot')->everyFiveMinutes();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        $consoleRoutes = base_path('routes/console.php');
        if (file_exists($consoleRoutes)) {
            require $consoleRoutes;
        }
    }
}
