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
     */
    public function checkPriceForUrl(string $url, int $subscriptionCount = null): array
    {
        $result = [
            'updated' => false,
            'notifications_sent' => 0,
        ];

        try {
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

            $lastHistory = $this->getLastPriceHistory($url);
            $previousPrice = $lastHistory?->price;

            if ($previousPrice !== null && bccomp($currentPrice, $previousPrice, 2) === 0) {
                $this->updateLastCheckedTime($url);
                return $result;
            }

            $priceHistory = $this->createPriceHistory([
                'listing_url' => $url,
                'price' => $currentPrice,
                'previous_price' => $previousPrice,
                'is_available' => true,
                'change_reason' => $previousPrice === null ? 'Initial price check' : 'Price changed',
                'checked_at' => now(),
            ]);

            $result['updated'] = true;

            if (!empty($listingData['title'])) {
                $this->updateSubscriptionTitles($url, $listingData['title']);
            }

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
     */
    private function getUniqueUrlsWithCounts(): Collection
    {
        return DB::table('subscriptions')
            ->select('listing_url', DB::raw('COUNT(*) as subscription_count'))
            ->where('is_verified', true)
            ->groupBy('listing_url')
            ->orderBy('subscription_count', 'desc')
            ->get();
    }

    /**
     * Get last price history with optimized query
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
     */
    private function createPriceHistory(array $data): PriceHistory
    {
        return PriceHistory::create($data);
    }

    /**
     * Update last checked time for existing price history
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
     */
    private function updateSubscriptionTitles(string $url, string $title): void
    {
        Subscription::where('listing_url', $url)
            ->whereNull('listing_title')
            ->update(['listing_title' => $title]);
    }

    /**
     * Notify subscribers with optimized batch processing
     */
    private function notifySubscribers(string $url, PriceHistory $priceHistory): int
    {
        $notifications_sent = 0;

        try {
            $subscriptions = Subscription::where('listing_url', $url)
                ->where('is_verified', true)
                ->select(['id', 'email', 'listing_title'])
                ->get();

            foreach ($subscriptions as $subscription) {
                try {
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
     */
    private function handleUnavailableListing(string $url, string $reason): void
    {
        try {
            DB::beginTransaction();

            PriceHistory::create([
                'listing_url' => $url,
                'price' => null,
                'previous_price' => null,
                'is_available' => false,
                'change_reason' => $reason,
                'checked_at' => now(),
            ]);

            $subscriptions = Subscription::where('listing_url', $url)
                ->where('is_verified', true)
                ->get();

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
     */
    private function handleInactiveListing(string $url, string $reason): void
    {
        $this->handleUnavailableListing($url, $reason);
    }

    /**
     * Get tracking statistics compatible with SQLite
     */
    public function getTrackingStatistics(): array
    {
        try {
            $stats = [];

            $stats['total_subscriptions'] = Subscription::where('is_verified', true)->count();
            $stats['total_listings'] = Subscription::where('is_verified', true)
                ->distinct('listing_url')->count();

            $totalChecks = PriceHistory::count();
            $stats['total_checks'] = $totalChecks;

            $today = now()->format('Y-m-d');
            $changesToday = PriceHistory::whereDate('checked_at', $today)
                ->whereNotNull('previous_price')
                ->count();
            $stats['changes_today'] = $changesToday;

            $activeListings = PriceHistory::where('is_available', true)
                ->distinct('listing_url')
                ->count();
            $stats['active_listings'] = $activeListings;

            $yesterday = now()->subDay();
            $stats['checks_last_24h'] = PriceHistory::where('checked_at', '>', $yesterday)->count();

            // Виправлено: використовуємо правильні назви колонок
            $avgChange = PriceHistory::whereNotNull('previous_price')
                ->whereNotNull('price')
                ->where('price', '>', 0)
                ->where('previous_price', '>', 0)
                ->get()
                ->map(function ($history) {
                    return (($history->price - $history->previous_price) / $history->previous_price) * 100;
                })
                ->avg();

            $stats['avg_price_change_percent'] = round($avgChange ?? 0, 2);

            $mostActive = PriceHistory::select('listing_url')
                ->selectRaw('COUNT(*) as check_count')
                ->groupBy('listing_url')
                ->orderByDesc('check_count')
                ->limit(5)
                ->get()
                ->pluck('check_count', 'listing_url')
                ->toArray();

            $stats['most_active_listings'] = $mostActive;

            $stats['system'] = [
                'database_size' => $this->getDatabaseSize(),
                'oldest_record' => PriceHistory::min('checked_at'),
                'newest_record' => PriceHistory::max('checked_at'),
            ];

            $stats['generated_at'] = now()->toISOString();

            Log::info('Tracking statistics generated successfully', [
                'total_subscriptions' => $stats['total_subscriptions'],
                'total_checks' => $stats['total_checks'],
            ]);

            return $stats;

        } catch (Exception $e) {
            Log::error('Failed to get tracking statistics', [
                'error' => $e->getMessage(),
            ]);

            return [
                'error' => 'Failed to retrieve statistics',
                'total_subscriptions' => 0,
                'total_checks' => 0,
                'changes_today' => 0,
                'active_listings' => 0,
            ];
        }
    }

    /**
     * Get database size (SQLite compatible)
     */
    private function getDatabaseSize(): string
    {
        try {
            if (config('database.default') === 'sqlite') {
                $dbPath = database_path('database.sqlite');
                if (file_exists($dbPath)) {
                    $bytes = filesize($dbPath);
                    $units = ['B', 'KB', 'MB', 'GB'];
                    $i = 0;
                    while ($bytes >= 1024 && $i < count($units) - 1) {
                        $bytes /= 1024;
                        $i++;
                    }
                    return round($bytes, 2) . ' ' . $units[$i];
                }
            }
            return 'Unknown';
        } catch (Exception $e) {
            Log::warning('Failed to get database size', ['error' => $e->getMessage()]);
            return 'Unknown';
        }
    }

    /**
     * Clean old price history records to optimize database
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
