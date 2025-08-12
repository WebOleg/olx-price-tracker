<?php

namespace App\Services;

use App\Jobs\SendEmailNotificationJob;
use App\Models\PriceHistory;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class PriceTrackerService
{
    private OlxParserService $olxParser;

    public function __construct(OlxParserService $olxParser)
    {
        $this->olxParser = $olxParser;
    }

    /**
     * Check prices for all active subscriptions
     *
     * @return array
     */
    public function checkAllPrices(): array
    {
        $results = [
            'checked' => 0,
            'updated' => 0,
            'errors' => 0,
            'notifications_sent' => 0,
        ];

        // Get unique URLs to avoid duplicate checks
        $uniqueUrls = Subscription::where('is_verified', true)
            ->where('is_active', true)
            ->distinct()
            ->pluck('listing_url');

        foreach ($uniqueUrls as $url) {
            try {
                $result = $this->checkPriceForUrl($url);
                $results['checked']++;

                if ($result['updated']) {
                    $results['updated']++;
                }

                $results['notifications_sent'] += $result['notifications_sent'];
            } catch (\Exception $e) {
                Log::error('Price check failed for URL', [
                    'url' => $url,
                    'error' => $e->getMessage()
                ]);
                $results['errors']++;
            }
        }

        return $results;
    }

    /**
     * Check price for specific URL and notify subscribers
     *
     * @param string $url
     * @return array
     */
    public function checkPriceForUrl(string $url): array
    {
        $result = [
            'updated' => false,
            'notifications_sent' => 0,
        ];

        // Parse current price
        $listingData = $this->olxParser->parseListingData($url);

        if (!$listingData || !isset($listingData['price'])) {
            Log::warning('Unable to parse listing data', ['url' => $url]);
            return $result;
        }

        $currentPrice = $listingData['price'];
        $listingTitle = $listingData['title'] ?? null;
        $isActive = $listingData['is_active'] ?? true;

        // Get last price history
        $lastHistory = PriceHistory::where('listing_url', $url)
            ->orderBy('checked_at', 'desc')
            ->first();

        $previousPrice = $lastHistory ? $lastHistory->price : null;
        $hasChanged = $previousPrice && $currentPrice != $previousPrice;

        // Create price history record
        $priceHistory = PriceHistory::create([
            'listing_url' => $url,
            'price' => $currentPrice,
            'previous_price' => $previousPrice,
            'change_type' => $this->determineChangeType($currentPrice, $previousPrice),
            'checked_at' => now(),
        ]);

        // Update subscriptions
        $subscriptions = Subscription::where('listing_url', $url)
            ->where('is_verified', true)
            ->where('is_active', true)
            ->get();

        foreach ($subscriptions as $subscription) {
            $subscription->update([
                'current_price' => $currentPrice,
                'listing_title' => $listingTitle ?: $subscription->listing_title,
                'last_checked_at' => now(),
                'is_active' => $isActive,
            ]);

            // Send notification if price changed
            if ($hasChanged) {
                $this->sendPriceChangeNotification($subscription, $priceHistory);
                $result['notifications_sent']++;
            }
        }

        if ($hasChanged) {
            $result['updated'] = true;
        }

        return $result;
    }

    /**
     * Send price change notification to subscriber
     *
     * @param Subscription $subscription
     * @param PriceHistory $priceHistory
     * @return void
     */
    private function sendPriceChangeNotification(Subscription $subscription, PriceHistory $priceHistory): void
    {
        SendEmailNotificationJob::dispatch($subscription, $priceHistory);
    }

    /**
     * Determine price change type
     *
     * @param float $currentPrice
     * @param float|null $previousPrice
     * @return string
     */
    private function determineChangeType(float $currentPrice, ?float $previousPrice): string
    {
        if (!$previousPrice) {
            return 'no_change';
        }

        if ($currentPrice > $previousPrice) {
            return 'increased';
        } elseif ($currentPrice < $previousPrice) {
            return 'decreased';
        }

        return 'no_change';
    }

    /**
     * Get price statistics for URL
     *
     * @param string $url
     * @param int $days
     * @return array
     */
    public function getPriceStatistics(string $url, int $days = 30): array
    {
        $histories = PriceHistory::where('listing_url', $url)
            ->where('checked_at', '>=', now()->subDays($days))
            ->orderBy('checked_at')
            ->get();

        if ($histories->isEmpty()) {
            return [
                'min_price' => null,
                'max_price' => null,
                'avg_price' => null,
                'price_changes' => 0,
                'last_change_date' => null,
            ];
        }

        $prices = $histories->pluck('price');
        $priceChanges = $histories->where('change_type', '!=', 'no_change')->count();
        $lastChangeDate = $histories->where('change_type', '!=', 'no_change')
            ->last()?->checked_at;

        return [
            'min_price' => $prices->min(),
            'max_price' => $prices->max(),
            'avg_price' => round($prices->avg(), 2),
            'price_changes' => $priceChanges,
            'last_change_date' => $lastChangeDate,
            'current_price' => $prices->last(),
            'total_checks' => $histories->count(),
        ];
    }

    /**
     * Clean old price history records
     *
     * @param int $daysToKeep
     * @return int
     */
    public function cleanOldPriceHistory(int $daysToKeep = 90): int
    {
        return PriceHistory::where('checked_at', '<', now()->subDays($daysToKeep))
            ->delete();
    }
}
