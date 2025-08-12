<?php

namespace App\Jobs;

use App\Services\PriceTrackerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class CheckPricesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes
    public int $tries = 3;
    public int $maxExceptions = 3;
    public int $backoff = 60; // 1 minute

    private ?string $specificUrl;

    /**
     * Create a new job instance.
     *
     * @param string|null $specificUrl Check specific URL or all URLs if null
     */
    public function __construct(?string $specificUrl = null)
    {
        $this->specificUrl = $specificUrl;
        $this->onQueue('price-checks');
    }

    /**
     * Execute the job with comprehensive error handling and monitoring.
     *
     * @param PriceTrackerService $priceTracker
     * @return void
     */
    public function handle(PriceTrackerService $priceTracker): void
    {
        $startTime = microtime(true);

        Log::info('Price check job started', [
            'job_id' => $this->job?->getJobId(),
            'specific_url' => $this->specificUrl,
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries,
        ]);

        try {
            if ($this->specificUrl) {
                $results = $priceTracker->checkPriceForUrl($this->specificUrl);
                $this->logJobCompletion($results, $startTime, 'specific_url');
            } else {
                $results = $priceTracker->checkAllPrices();
                $this->logJobCompletion($results, $startTime, 'all_urls');
            }

        } catch (Exception $e) {
            $this->handleJobFailure($e, $startTime);
            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    /**
     * Handle job failure with detailed logging
     *
     * @param Exception $exception
     * @return void
     */
    public function failed(Exception $exception): void
    {
        Log::error('Price check job failed permanently', [
            'job_id' => $this->job?->getJobId(),
            'specific_url' => $this->specificUrl,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Could send notification to admin about job failure
        // $this->notifyAdminOfJobFailure($exception);
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array
     */
    public function backoff(): array
    {
        return [60, 120, 300]; // 1 min, 2 min, 5 min
    }

    /**
     * Determine if the job should be retried based on the exception
     *
     * @param Exception $exception
     * @return bool
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHours(2); // Retry for maximum 2 hours
    }

    /**
     * Log successful job completion
     *
     * @param array $results
     * @param float $startTime
     * @param string $type
     * @return void
     */
    private function logJobCompletion(array $results, float $startTime, string $type): void
    {
        $executionTime = round(microtime(true) - $startTime, 2);

        Log::info('Price check job completed successfully', [
            'job_id' => $this->job?->getJobId(),
            'type' => $type,
            'specific_url' => $this->specificUrl,
            'execution_time' => $executionTime,
            'results' => $results,
            'attempt' => $this->attempts(),
        ]);

        // Log metrics for monitoring
        $this->recordMetrics($results, $executionTime, $type);
    }

    /**
     * Handle job failure with comprehensive logging
     *
     * @param Exception $e
     * @param float $startTime
     * @return void
     */
    private function handleJobFailure(Exception $e, float $startTime): void
    {
        $executionTime = round(microtime(true) - $startTime, 2);

        Log::error('Price check job failed', [
            'job_id' => $this->job?->getJobId(),
            'specific_url' => $this->specificUrl,
            'execution_time' => $executionTime,
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries,
            'will_retry' => $this->attempts() < $this->tries,
            'error' => $e->getMessage(),
            'error_class' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    }

    /**
     * Record metrics for monitoring and analytics
     *
     * @param array $results
     * @param float $executionTime
     * @param string $type
     * @return void
     */
    private function recordMetrics(array $results, float $executionTime, string $type): void
    {
        // This could integrate with monitoring services like DataDog, New Relic, etc.
        $metrics = [
            'price_check.execution_time' => $executionTime,
            'price_check.urls_checked' => $results['checked'] ?? 0,
            'price_check.urls_updated' => $results['updated'] ?? 0,
            'price_check.errors' => $results['errors'] ?? 0,
            'price_check.notifications_sent' => $results['notifications_sent'] ?? 0,
        ];

        foreach ($metrics as $metric => $value) {
            Log::info('Metric recorded', [
                'metric' => $metric,
                'value' => $value,
                'type' => $type,
                'timestamp' => now()->toISOString(),
            ]);
        }
    }

    /**
     * Get unique job identifier for deduplication
     *
     * @return string
     */
    public function uniqueId(): string
    {
        return $this->specificUrl
            ? 'check_price_' . md5($this->specificUrl)
            : 'check_all_prices';
    }

    /**
     * The tags for the job (useful for monitoring)
     *
     * @return array
     */
    public function tags(): array
    {
        $tags = ['price-check'];

        if ($this->specificUrl) {
            $tags[] = 'specific-url';
            $tags[] = 'url:' . parse_url($this->specificUrl, PHP_URL_HOST);
        } else {
            $tags[] = 'all-urls';
        }

        return $tags;
    }
}
