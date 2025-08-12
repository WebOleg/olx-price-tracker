<?php

namespace App\Console;

use App\Console\Commands\CheckPricesCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Price check every 15 minutes (main schedule)
        $schedule->command('prices:check')
            ->everyFifteenMinutes()
            ->withoutOverlapping(30) // Prevent overlapping jobs, timeout after 30 min
            ->runInBackground()
            ->onSuccess(function () {
                \Log::info('Scheduled price check completed successfully');
            })
            ->onFailure(function () {
                \Log::error('Scheduled price check failed');
            });

        // Cleanup old price history daily at 2 AM
        $schedule->command('prices:check --cleanup')
            ->dailyAt('02:00')
            ->withoutOverlapping(60) // 1 hour timeout
            ->runInBackground()
            ->onSuccess(function () {
                \Log::info('Scheduled cleanup completed successfully');
            });

        // Queue restart every 6 hours (keeps workers fresh)
        $schedule->command('queue:restart')
            ->everySixHours()
            ->onSuccess(function () {
                \Log::info('Queue workers restarted successfully');
            });

        // Clear cache weekly
        $schedule->command('cache:clear')
            ->weekly()
            ->sundays()
            ->at('03:00');

        // Generate queue statistics for monitoring
        $schedule->call(function () {
            $queueStats = [
                'pending_jobs' => \DB::table('jobs')->count(),
                'failed_jobs' => \DB::table('failed_jobs')->count(),
                'timestamp' => now()->toISOString(),
            ];

            \Log::info('Queue statistics', $queueStats);
        })
            ->hourly()
            ->name('queue-stats')
            ->withoutOverlapping();

        // Health check ping (useful for monitoring services)
        $schedule->call(function () {
            try {
                // Test database connection
                \DB::connection()->getPdo();

                // Test Redis connection
                \Illuminate\Support\Facades\Redis::ping();

                \Log::info('Health check passed', [
                    'timestamp' => now()->toISOString(),
                    'database' => 'healthy',
                    'redis' => 'healthy',
                ]);

            } catch (\Exception $e) {
                \Log::error('Health check failed', [
                    'error' => $e->getMessage(),
                    'timestamp' => now()->toISOString(),
                ]);
            }
        })
            ->everyFiveMinutes()
            ->name('health-check');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }

    /**
     * Get the timezone that should be used by default for scheduled events.
     */
    protected function scheduleTimezone(): string
    {
        return 'Europe/Kiev'; // Ukrainian timezone
    }
}
