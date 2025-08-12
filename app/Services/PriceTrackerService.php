<?php

namespace App\Services;

use App\Jobs\SendEmailNotificationJob;
use App\Models\PriceHistory;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Exception;

class PriceTrackerService
{
    private OlxParserService $olxParser;
    private EmailService $emailService;

    public function __construct(OlxParserService $olxParser, EmailService $emailService)
    {
        $this->olxParser = $olxParser;
        $this->emailService = $emailService;
    }

    /**
     * Check prices for all active subscriptions with optimized queries
     *
     * @return array
     */
    public function checkAllPrices(): array
    {
        $startTime = microtime(true);

        $results = [
            'checked' => 0,
            'updated' => 0,
            'errors' => 0,
            'notifications_sent' => 0,
            'execution_time' => 0,
        ];

        try {
            // Optimized query: get unique URLs with subscription count in single query
            $uniqueUrls = $this->getUniqueUrlsWithCounts();

            Log::info('Price check started', [
                'unique_urls_count' => $uniqueUrls->count(),
                'total_subscriptions' => $uniqueUrls->sum('subscription_count'),
            ]);

            foreach ($uniqueUrls as $urlData) {
                try {
                    $result = $this->checkPriceForUrl($urlData->listing_url, $urlData->subscription_count);
                    $results['checked']++;

                    if ($result['updated']) {
                        $results['updated']++;
                    }

                    $results['notifications_sent'] += $result['notifications_sent'];

                    // Add small delay to avoid overwhelming OLX
                    usleep(500000); // 0.5 seconds

                } catch (Exception $e) {
                    Log::error('Price check failed for URL', [
                        'url' => $urlData->listing_url,
                        'subscription_count' => $urlData->subscription_count,
                        'error' => $e->getMessage(),
                    ]);
                    $results['errors']++;
                }
            }

        } catch (Exception $e) {
            Log::error('Failed to check prices', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $results['errors']++;
        }

        $results['execution_time'] = round(microtime(true) - $startTime, 2);

        Log::info('Price check completed', $results);

        return $results;
    }

    /**
     * Check price for specific URL and notify subscribers with batch processing
     *
     * @param string $url
     * @param int $subscriptionCount
     * @return array
     */
    public function checkPriceForUrl(string $url, int $subscriptionCount = null): array
    {
        $result = [
            'updated' => false,
            'notifications_sent' => 0,
        ];

        try {
            // Parse listing data
            $listingData = $this->olxParser->parseListingData($url);

            if (!$listingData) {
                $this->handleUnavailableListing($url, 'Unable to access listing');
                return $result;
            }

            if (isset($listingData['is_active']) && !$listingData['is_active']) {
                $this->handleInactiveListing($url, 'Listing is no longer active');
                return $result;
            }

            if (!isset($listingData['price']) || $listingData['price'] === null) {
                Log::warning('Price not available for listing', [
                    'url' => $url,
                    'listing_data' => $listingData,
                ]);
                return $result;
            }

            $currentPrice = (float) $listingData['price'];

            // Get last price history with optimized query
            $lastHistory = $this->getLastPriceHistory($url);
            $previousPrice = $lastHistory?->price;

            // Check if price actually changed
            if ($previousPrice !== null && bccomp($currentPrice, $previousPrice, 2) === 0) {
                // Price didn't change, just update checked_at
                $this->updateLastCheckedTime($url);
                return $result;
            }

            // Create new price history record
            $priceHistory = $this->createPriceHistory([
                'listing_url' => $url,
                'price' => $currentPrice,
                'previous_price' => $previousPrice,
                'is_available' => true,
                'change_reason' => $previousPrice === null ? 'Initial price check' : 'Price changed',
                'checked_at' => now(),
            ]);

            $result['updated'] = true;

            // Update subscription titles if available
            if (!empty($listingData['title'])) {
                $this->updateSubscriptionTitles($url, $listingData['title']);
            }

            // Send notifications only if price changed (not initial)
            if ($previousPrice !== null) {
                $result['notifications_sent'] = $this->notifySubscribers($url, $priceHistory);
            }

            Log::info('Price updated successfully', [
                'url' => $url,
                'new_price' => $currentPrice,
                'previous_price' => $previousPrice,
                'change' => $priceHistory->getFormattedPriceChange(),
                'notifications_sent' => $result['notifications_sent'],
            ]);

        } catch (Exception $e) {
            Log::error('Failed to check price for URL', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        return $result;
    }

    /**
     * Get unique URLs with subscription counts using optimized query
     *
     * @return Collection
     */
    private function getUniqueUrlsWithCounts(): Collection
    {
        return DB::table('subscriptions')
            ->select('listing_url', DB::raw('COUNT(*) as subscription_count'))
            ->where('is_verified', true)
            ->groupBy('listing_url')
            ->orderBy('subscription_count', 'desc') // Check most popular URLs first
            ->get();
    }

    /**
     * Get last price history with optimized query
     *
     * @param string $url
     * @return PriceHistory|null
     */
    private function getLastPriceHistory(string $url): ?PriceHistory
    {
        return PriceHistory::where('listing_url', $url)
            ->select(['price', 'checked_at'])
            ->orderBy('checked_at', 'desc')
            ->first();
    }

    /**
     * Create price history record with validation
     *
     * @param array $data
     * @return PriceHistory
     */
    private function createPriceHistory(array $data): PriceHistory
    {
        return PriceHistory::create($data);
    }

    /**
     * Update last checked time for existing price history
     *
     * @param string $url
     * @return void
     */
    private function updateLastCheckedTime(string $url): void
    {
        PriceHistory::where('listing_url', $url)
            ->orderBy('checked_at', 'desc')
            ->limit(1)
            ->update(['checked_at' => now()]);
    }

    /**
     * Update subscription titles with batch update
     *
     * @param string $url
     * @param string $title
     * @return void
     */
    private function updateSubscriptionTitles(string $url, string $title): void
    {
        Subscription::where('listing_url', $url)
            ->whereNull('listing_title')
            ->update(['listing_title' => $title]);
    }

    /**
     * Notify subscribers with optimized batch processing
     *
     * @param string $url
     * @param PriceHistory $priceHistory
     * @return int
     */
    private function notifySubscribers(string $url, PriceHistory $priceHistory): int
    {
        $notifications_sent = 0;

        try {
            // Get all verified subscriptions for this URL with optimized query
            $subscriptions = Subscription::where('listing_url', $url)
                ->where('is_verified', true)
                ->select(['id', 'email', 'listing_title'])
                ->get();

            foreach ($subscriptions as $subscription) {
                try {
                    // Use job for async email sending to avoid blocking
                    SendEmailNotificationJob::dispatch($subscription, $priceHistory);
                    $notifications_sent++;

                } catch (Exception $e) {
                    Log::error('Failed to queue notification', [
                        'subscription_id' => $subscription->id,
                        'email' => $subscription->email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Notifications queued successfully', [
                'url' => $url,
                'subscriptions_count' => $subscriptions->count(),
                'notifications_queued' => $notifications_sent,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to notify subscribers', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return $notifications_sent;
    }

    /**
     * Handle unavailable listing with cleanup
     *
     * @param string $url
     * @param string $reason
     * @return void
     */
    private function handleUnavailableListing(string $url, string $reason): void
    {
        try {
            DB::beginTransaction();

            // Create price history record for unavailable listing
            PriceHistory::create([
                'listing_url' => $url,
                'price' => null,
                'previous_price' => null,
                'is_available' => false,
                'change_reason' => $reason,
                'checked_at' => now(),
            ]);

            // Get subscriptions to notify
            $subscriptions = Subscription::where('listing_url', $url)
                ->where('is_verified', true)
                ->get();

            // Send unavailable notifications
            foreach ($subscriptions as $subscription) {
                try {
                    $this->emailService->sendUnavailableNotification($subscription, $reason);
                } catch (Exception $e) {
                    Log::error('Failed to send unavailable notification', [
                        'subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Optionally delete subscriptions for unavailable listings
            // Subscription::where('listing_url', $url)->delete();

            DB::commit();

            Log::info('Handled unavailable listing', [
                'url' => $url,
                'reason' => $reason,
                'affected_subscriptions' => $subscriptions->count(),
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to handle unavailable listing', [
                'url' => $url,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle inactive listing
     *
     * @param string $url
     * @param string $reason
     * @return void
     */
    private function handleInactiveListing(string $url, string $reason): void
    {
        $this->handleUnavailableListing($url, $reason);
    }

    /**
     * Get price tracking statistics with optimized queries
     *
     * @return array
     */
    public function getTrackingStatistics(): array
    {
        try {
            $stats = [
                'total_subscriptions' => 0,
                'verified_subscriptions' => 0,
                'unique_listings' => 0,
                'total_price_checks' => 0,
                'price_changes_today' => 0,
                'active_listings' => 0,
            ];

            // Use optimized aggregate queries
            $subscriptionStats = DB::table('subscriptions')
                ->selectRaw('
                    COUNT(*) as total,
                    SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified,
                    COUNT(DISTINCT listing_url) as unique_urls
                ')
                ->first();

            $stats['total_subscriptions'] = $subscriptionStats->total;
            $stats['verified_subscriptions'] = $subscriptionStats->verified;
            $stats['unique_listings'] = $subscriptionStats->unique_urls;

            $priceStats = DB::table('price_histories')
                ->selectRaw('
                    COUNT(*) as total_checks,
                    SUM(CASE WHEN DATE(checked_at) = CURDATE() AND previous_price IS NOT NULL THEN 1 ELSE 0 END) as changes_today,
                    COUNT(DISTINCT CASE WHEN is_available = 1 THEN listing_url END) as active_listings
                ')
                ->first();

            $stats['total_price_checks'] = $priceStats->total_checks;
            $stats['price_changes_today'] = $priceStats->changes_today;
            $stats['active_listings'] = $priceStats->active_listings;

            return $stats;

        } catch (Exception $e) {
            Log::error('Failed to get tracking statistics', [
                'error' => $e->getMessage(),
            ]);

            return [
                'error' => 'Failed to retrieve statistics',
            ];
        }
    }

    /**
     * Clean old price history records to optimize database
     *
     * @param int $daysToKeep
     * @return int
     */
    public function cleanOldPriceHistory(int $daysToKeep = 90): int
    {
        try {
            $cutoffDate = now()->subDays($daysToKeep);

            $deletedCount = PriceHistory::where('checked_at', '<', $cutoffDate)
                ->delete();

            Log::info('Cleaned old price history records', [
                'deleted_count' => $deletedCount,
                'cutoff_date' => $cutoffDate,
            ]);

            return $deletedCount;

        } catch (Exception $e) {
            Log::error('Failed to clean old price history', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
