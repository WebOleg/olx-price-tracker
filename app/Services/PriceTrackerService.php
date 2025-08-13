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
     * Send verification email to subscriber
     *
     * @param Subscription $subscription
     * @return bool
     */
    public function sendVerificationEmail(Subscription $subscription): bool
    {
        try {
            return $this->emailService->sendVerificationEmail(
                $subscription->email,
                $subscription->verification_token
            );
        } catch (Exception $e) {
            Log::error('Failed to send verification email via PriceTrackerService', [
                'subscription_id' => $subscription->id,
                'email' => $subscription->email,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Check prices for all active subscriptions
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
     * Check price for specific URL
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
                $this->handleUnavailableListing($url, 'Failed to parse listing data');
                return $result;
            }

            if (!$listingData['is_active']) {
                $this->handleInactiveListing($url, 'Listing is no longer active');
                return $result;
            }

            // Get the latest price history
            $latestPrice = PriceHistory::where('listing_url', $url)
                ->latest('checked_at')
                ->first();

            $currentPrice = $listingData['price'];
            $previousPrice = $latestPrice ? $latestPrice->price : null;

            // Create new price history record
            $priceHistory = PriceHistory::create([
                'listing_url' => $url,
                'price' => $currentPrice,
                'previous_price' => $previousPrice,
                'is_available' => true,
                'checked_at' => now(),
            ]);

            // Check if price changed
            if ($previousPrice && $currentPrice != $previousPrice) {
                $result['updated'] = true;
                $result['notifications_sent'] = $this->notifySubscribers($url, $priceHistory);
            }

        } catch (Exception $e) {
            Log::error('Failed to check price for URL', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Get unique URLs with subscription counts
     */
    private function getUniqueUrlsWithCounts(): Collection
    {
        return Subscription::select('listing_url')
            ->selectRaw('COUNT(*) as subscription_count')
            ->where('is_verified', true)
            ->groupBy('listing_url')
            ->get();
    }

    /**
     * Notify subscribers about price changes
     */
    private function notifySubscribers(string $url, PriceHistory $priceHistory): int
    {
        $notifications_sent = 0;

        try {
            $subscriptions = Subscription::where('listing_url', $url)
                ->where('is_verified', true)
                ->get();

            foreach ($subscriptions as $subscription) {
                try {
                    SendEmailNotificationJob::dispatch($subscription, $priceHistory);
                    $notifications_sent++;
                } catch (Exception $e) {
                    Log::error('Failed to queue notification', [
                        'subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (Exception $e) {
            Log::error('Failed to notify subscribers', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return $notifications_sent;
    }

    /**
     * Handle unavailable listing
     */
    private function handleUnavailableListing(string $url, string $reason): void
    {
        try {
            PriceHistory::create([
                'listing_url' => $url,
                'price' => null,
                'previous_price' => null,
                'is_available' => false,
                'checked_at' => now(),
            ]);

            Log::info('Handled unavailable listing', [
                'url' => $url,
                'reason' => $reason,
            ]);

        } catch (Exception $e) {
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
}
