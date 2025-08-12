<?php

namespace App\Jobs;

use App\Models\PriceHistory;
use App\Models\Subscription;
use App\Services\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class SendEmailNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60; // 1 minute
    public int $tries = 3;
    public int $maxExceptions = 2;
    public int $backoff = 30; // 30 seconds

    private Subscription $subscription;
    private PriceHistory $priceHistory;
    private string $notificationType;

    /**
     * Create a new job instance.
     *
     * @param Subscription $subscription
     * @param PriceHistory $priceHistory
     * @param string $notificationType
     */
    public function __construct(
        Subscription $subscription,
        PriceHistory $priceHistory,
        string $notificationType = 'price_change'
    ) {
        $this->subscription = $subscription;
        $this->priceHistory = $priceHistory;
        $this->notificationType = $notificationType;
        $this->onQueue('emails');
    }

    /**
     * Execute the job with comprehensive error handling
     *
     * @param EmailService $emailService
     * @return void
     */
    public function handle(EmailService $emailService): void
    {
        $startTime = microtime(true);

        Log::info('Email notification job started', [
            'job_id' => $this->job?->getJobId(),
            'subscription_id' => $this->subscription->id,
            'email' => $this->subscription->email,
            'notification_type' => $this->notificationType,
            'attempt' => $this->attempts(),
        ]);

        try {
            // Verify subscription is still active and verified
            if (!$this->isSubscriptionValid()) {
                Log::warning('Skipping email notification for invalid subscription', [
                    'subscription_id' => $this->subscription->id,
                    'email' => $this->subscription->email,
                    'is_verified' => $this->subscription->is_verified,
                ]);
                return;
            }

            $success = false;

            switch ($this->notificationType) {
                case 'price_change':
                    $success = $emailService->sendPriceChangeNotification(
                        $this->subscription,
                        $this->priceHistory
                    );
                    break;

                case 'unavailable':
                    $success = $emailService->sendUnavailableNotification(
                        $this->subscription,
                        $this->priceHistory->change_reason ?? 'Listing unavailable'
                    );
                    break;

                case 'verification':
                    // Handle verification emails if needed
                    Log::warning('Verification emails should not use this job', [
                        'subscription_id' => $this->subscription->id,
                    ]);
                    return;

                default:
                    throw new Exception("Unknown notification type: {$this->notificationType}");
            }

            if (!$success) {
                throw new Exception('Email service returned false for email sending');
            }

            $this->logJobSuccess($startTime);

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
        Log::error('Email notification job failed permanently', [
            'job_id' => $this->job?->getJobId(),
            'subscription_id' => $this->subscription->id,
            'email' => $this->subscription->email,
            'notification_type' => $this->notificationType,
            'attempts' => $this->attempts(),
            'price_change' => $this->priceHistory->getFormattedPriceChange(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Could implement dead letter queue or alert admins
        $this->handlePermanentFailure($exception);
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array
     */
    public function backoff(): array
    {
        return [30, 60, 120]; // 30 sec, 1 min, 2 min
    }

    /**
     * Determine when the job should timeout
     *
     * @return \DateTime
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHours(1); // Retry for maximum 1 hour
    }

    /**
     * Check if subscription is still valid for notification
     *
     * @return bool
     */
    private function isSubscriptionValid(): bool
    {
        // Refresh model from database to get latest state
        $this->subscription->refresh();

        return $this->subscription->is_verified && !$this->subscription->trashed();
    }

    /**
     * Log successful job completion
     *
     * @param float $startTime
     * @return void
     */
    private function logJobSuccess(float $startTime): void
    {
        $executionTime = round(microtime(true) - $startTime, 3);

        Log::info('Email notification job completed successfully', [
            'job_id' => $this->job?->getJobId(),
            'subscription_id' => $this->subscription->id,
            'email' => $this->subscription->email,
            'notification_type' => $this->notificationType,
            'execution_time' => $executionTime,
            'attempt' => $this->attempts(),
            'price_change' => $this->priceHistory->getFormattedPriceChange(),
        ]);

        // Record metrics
        $this->recordEmailMetrics($executionTime, true);
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
        $executionTime = round(microtime(true) - $startTime, 3);

        Log::error('Email notification job failed', [
            'job_id' => $this->job?->getJobId(),
            'subscription_id' => $this->subscription->id,
            'email' => $this->subscription->email,
            'notification_type' => $this->notificationType,
            'execution_time' => $executionTime,
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries,
            'will_retry' => $this->attempts() < $this->tries,
            'error' => $e->getMessage(),
            'error_class' => get_class($e),
        ]);

        // Record failure metrics
        $this->recordEmailMetrics($executionTime, false);
    }

    /**
     * Handle permanent failure (after all retries)
     *
     * @param Exception $exception
     * @return void
     */
    private function handlePermanentFailure(Exception $exception): void
    {
        // Could implement:
        // 1. Dead letter queue for manual processing
        // 2. Alert administrators
        // 3. Store failure in database for analysis
        // 4. Attempt alternative notification methods

        Log::critical('Email notification permanently failed', [
            'subscription_id' => $this->subscription->id,
            'email' => $this->subscription->email,
            'notification_type' => $this->notificationType,
            'final_error' => $exception->getMessage(),
        ]);
    }

    /**
     * Record email metrics for monitoring
     *
     * @param float $executionTime
     * @param bool $success
     * @return void
     */
    private function recordEmailMetrics(float $executionTime, bool $success): void
    {
        $metrics = [
            'email.execution_time' => $executionTime,
            'email.success' => $success ? 1 : 0,
            'email.failure' => $success ? 0 : 1,
            'email.attempt' => $this->attempts(),
        ];

        foreach ($metrics as $metric => $value) {
            Log::info('Email metric recorded', [
                'metric' => $metric,
                'value' => $value,
                'notification_type' => $this->notificationType,
                'timestamp' => now()->toISOString(),
            ]);
        }
    }

    /**
     * Get unique job identifier to prevent duplicate emails
     *
     * @return string
     */
    public function uniqueId(): string
    {
        return sprintf(
            'email_%s_%d_%d_%s',
            $this->notificationType,
            $this->subscription->id,
            $this->priceHistory->id,
            $this->priceHistory->checked_at->format('Y-m-d-H-i')
        );
    }

    /**
     * The tags for the job (useful for monitoring)
     *
     * @return array
     */
    public function tags(): array
    {
        return [
            'email-notification',
            'type:' . $this->notificationType,
            'subscription:' . $this->subscription->id,
            'email:' . md5($this->subscription->email),
        ];
    }

    /**
     * Middleware that the job should pass through
     *
     * @return array
     */
    public function middleware(): array
    {
        return [
            // Could add rate limiting middleware here
            // new \App\Jobs\Middleware\RateLimited('emails', 10, 60),
        ];
    }
}
