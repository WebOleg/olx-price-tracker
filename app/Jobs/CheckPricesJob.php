<?php

namespace App\Jobs;

use App\Services\PriceTrackerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckPricesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 3;
    public int $backoff = 60;

    /**
     * Execute the job to check all subscription prices
     *
     * @param PriceTrackerService $priceTracker
     * @return void
     */
    public function handle(PriceTrackerService $priceTracker): void
    {
        Log::info('Starting price check job');

        $results = $priceTracker->checkAllPrices();

        Log::info('Price check job completed', $results);
    }

    /**
     * Handle job failure
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Price check job failed', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
