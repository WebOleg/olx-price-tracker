<?php

namespace App\Console\Commands;

use App\Jobs\CheckPricesJob;
use App\Services\PriceTrackerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Exception;

class CheckPricesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prices:check
                            {--url= : Check specific URL instead of all}
                            {--sync : Run synchronously instead of queuing job}
                            {--force : Force check even if recent check exists}
                            {--cleanup : Clean old price history after check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check prices for OLX listings and send notifications';

    private PriceTrackerService $priceTrackerService;

    /**
     * Create a new command instance.
     */
    public function __construct(PriceTrackerService $priceTrackerService)
    {
        parent::__construct();
        $this->priceTrackerService = $priceTrackerService;
    }

    /**
     * Execute the console command with comprehensive options
     *
     * @return int
     */
    public function handle(): int
    {
        $startTime = microtime(true);

        $this->info('🔍 Starting OLX price check...');

        try {
            $specificUrl = $this->option('url');
            $sync = $this->option('sync');
            $force = $this->option('force');
            $cleanup = $this->option('cleanup');

            // Validate URL if provided
            if ($specificUrl && !$this->isValidOlxUrl($specificUrl)) {
                $this->error('❌ Invalid OLX URL provided');
                return 1;
            }

            // Check if recent check exists (unless forced)
            if (!$force && !$specificUrl && $this->hasRecentCheck()) {
                $this->warn('⏰ Recent price check detected. Use --force to override.');
                return 0;
            }

            if ($sync) {
                // Run synchronously
                $results = $this->runSynchronously($specificUrl);
                $this->displayResults($results, microtime(true) - $startTime);
            } else {
                // Queue job for asynchronous processing
                $this->queueJob($specificUrl);
                $this->info('📋 Price check job queued successfully');
            }

            // Clean old records if requested
            if ($cleanup) {
                $this->runCleanup();
            }

            Log::info('Price check command completed', [
                'specific_url' => $specificUrl,
                'sync' => $sync,
                'force' => $force,
                'cleanup' => $cleanup,
                'execution_time' => round(microtime(true) - $startTime, 2),
            ]);

            return 0;

        } catch (Exception $e) {
            $this->error('❌ Price check failed: ' . $e->getMessage());

            Log::error('Price check command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 1;
        }
    }

    /**
     * Run price check synchronously
     *
     * @param string|null $specificUrl
     * @return array
     */
    private function runSynchronously(?string $specificUrl): array
    {
        $this->info('🔄 Running price check synchronously...');

        if ($specificUrl) {
            $this->line("Checking URL: {$specificUrl}");
            return $this->priceTrackerService->checkPriceForUrl($specificUrl);
        } else {
            $this->line('Checking all active subscriptions...');
            return $this->priceTrackerService->checkAllPrices();
        }
    }

    /**
     * Queue price check job
     *
     * @param string|null $specificUrl
     * @return void
     */
    private function queueJob(?string $specificUrl): void
    {
        CheckPricesJob::dispatch($specificUrl);

        if ($specificUrl) {
            $this->line("Queued job for URL: {$specificUrl}");
        } else {
            $this->line('Queued job for all active subscriptions');
        }
    }

    /**
     * Display results in a formatted table
     *
     * @param array $results
     * @param float $executionTime
     * @return void
     */
    private function displayResults(array $results, float $executionTime): void
    {
        $this->info('✅ Price check completed!');
        $this->newLine();

        // Results table
        $this->table(
            ['Metric', 'Value'],
            [
                ['URLs Checked', $results['checked'] ?? 0],
                ['Prices Updated', $results['updated'] ?? 0],
                ['Errors', $results['errors'] ?? 0],
                ['Notifications Sent', $results['notifications_sent'] ?? 0],
                ['Execution Time', round($executionTime, 2) . 's'],
            ]
        );

        // Status indicators
        if (($results['errors'] ?? 0) > 0) {
            $this->warn("⚠️  {$results['errors']} errors occurred during price check");
        }

        if (($results['updated'] ?? 0) > 0) {
            $this->info("📈 {$results['updated']} price changes detected");
        }

        if (($results['notifications_sent'] ?? 0) > 0) {
            $this->info("📧 {$results['notifications_sent']} notifications sent");
        }
    }

    /**
     * Run cleanup of old price history
     *
     * @return void
     */
    private function runCleanup(): void
    {
        $this->info('🧹 Cleaning old price history...');

        $daysToKeep = 90; // Keep 3 months of history
        $deletedCount = $this->priceTrackerService->cleanOldPriceHistory($daysToKeep);

        $this->info("🗑️  Deleted {$deletedCount} old price history records");
    }

    /**
     * Check if recent price check exists
     *
     * @return bool
     */
    private function hasRecentCheck(): bool
    {
        try {
            $recentCheck = \DB::table('price_histories')
                ->where('checked_at', '>', now()->subMinutes(30))
                ->exists();

            return $recentCheck;
        } catch (Exception $e) {
            Log::warning('Failed to check for recent price checks', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Validate OLX URL format
     *
     * @param string $url
     * @return bool
     */
    private function isValidOlxUrl(string $url): bool
    {
        return preg_match('/^https:\/\/(www\.)?olx\.ua\/.*\/obyavlenie\/.+/', $url) === 1;
    }

    /**
     * Get command help text
     *
     * @return string
     */
    public function getHelp(): string
    {
        return '
🔍 OLX Price Check Command

This command checks prices for OLX listings and sends notifications to subscribers.

Usage Examples:
  php artisan prices:check                    # Queue job for all subscriptions
  php artisan prices:check --sync             # Run synchronously for all
  php artisan prices:check --url=https://...  # Check specific URL
  php artisan prices:check --sync --cleanup   # Run sync + cleanup old data
  php artisan prices:check --force            # Force check ignoring recent runs

Options:
  --url       Check only specific OLX URL
  --sync      Run synchronously instead of queuing
  --force     Force check even if recent check exists
  --cleanup   Clean old price history after check

The command is typically run via cron every 15-30 minutes.
        ';
    }
}
