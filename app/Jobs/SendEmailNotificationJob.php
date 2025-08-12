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

class SendEmailNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 3;
    public int $backoff = 30;

    private Subscription $subscription;
    private PriceHistory $priceHistory;

    public function __construct(Subscription $subscription, PriceHistory $priceHistory)
    {
        $this->subscription = $subscription;
        $this->priceHistory = $priceHistory;
    }

    /**
     * Execute the job to send price change notification
     *
     * @param EmailService $emailService
     * @return void
     */
    public function handle(EmailService $emailService): void
    {
        Log::info('Sending price change notification', [
            'email' => $this->subscription->email,
            'url' => $this->subscription->listing_url,
            'price_change' => $this->priceHistory->getFormattedPriceChange()
        ]);

        $emailService->sendPriceChangeNotification($this->subscription, $this->priceHistory);
    }

    /**
     * Handle job failure
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Email notification failed', [
            'subscription_id' => $this->subscription->id,
            'email' => $this->subscription->email,
            'error' => $exception->getMessage()
        ]);
    }
}
