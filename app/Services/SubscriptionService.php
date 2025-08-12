<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\PriceHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class SubscriptionService
{
    private OlxParserService $olxParser;
    private EmailService $emailService;

    public function __construct(OlxParserService $olxParser, EmailService $emailService)
    {
        $this->olxParser = $olxParser;
        $this->emailService = $emailService;
    }

    /**
     * Create new subscription with email verification
     *
     * @param string $listingUrl
     * @param string $email
     * @return array
     */
    public function createSubscription(string $listingUrl, string $email): array
    {
        try {
            DB::beginTransaction();

            // Parse listing data to validate URL and get title
            $listingData = $this->olxParser->parseListingData($listingUrl);

            if (!$listingData) {
                return [
                    'success' => false,
                    'message' => 'Unable to access the listing. Please check the URL.',
                ];
            }

            if (isset($listingData['is_active']) && !$listingData['is_active']) {
                return [
                    'success' => false,
                    'message' => 'This listing is no longer active.',
                ];
            }

            // Check for existing unverified subscription
            $existingSubscription = Subscription::where('email', $email)
                ->where('listing_url', $listingUrl)
                ->where('is_verified', false)
                ->first();

            if ($existingSubscription) {
                if ($existingSubscription->isVerificationExpired()) {
                    // Delete expired subscription
                    $existingSubscription->delete();
                } else {
                    return [
                        'success' => false,
                        'message' => 'Verification email already sent. Please check your inbox or wait for expiration.',
                    ];
                }
            }

            // Create new subscription
            $subscription = new Subscription([
                'email' => $email,
                'listing_url' => $listingUrl,
                'listing_title' => $listingData['title'] ?? null,
                'is_verified' => false,
            ]);

            $verificationToken = $subscription->generateVerificationToken();
            $subscription->save();

            // Create initial price history
            if (isset($listingData['price']) && $listingData['price'] !== null) {
                PriceHistory::create([
                    'listing_url' => $listingUrl,
                    'price' => $listingData['price'],
                    'previous_price' => null,
                    'is_available' => true,
                    'change_reason' => 'Initial subscription',
                    'checked_at' => now(),
                ]);
            }

            // Send verification email
            $emailSent = $this->emailService->sendVerificationEmail($email, $verificationToken);

            if (!$emailSent) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'Failed to send verification email. Please try again.',
                ];
            }

            DB::commit();

            Log::info('Subscription created', [
                'subscription_id' => $subscription->id,
                'email' => $email,
                'listing_url' => $listingUrl,
            ]);

            return [
                'success' => true,
                'message' => 'Subscription created successfully. Please check your email to verify.',
                'data' => [
                    'subscription_id' => $subscription->id,
                    'verification_required' => true,
                ],
            ];

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to create subscription', [
                'email' => $email,
                'listing_url' => $listingUrl,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred while creating subscription. Please try again.',
            ];
        }
    }

    /**
     * Verify email subscription
     *
     * @param string $token
     * @return array
     */
    public function verifySubscription(string $token): array
    {
        try {
            $subscription = Subscription::where('verification_token', $token)
                ->where('is_verified', false)
                ->first();

            if (!$subscription) {
                return [
                    'success' => false,
                    'message' => 'Invalid or expired verification token.',
                ];
            }

            if ($subscription->isVerificationExpired()) {
                $subscription->delete();
                return [
                    'success' => false,
                    'message' => 'Verification token has expired. Please create a new subscription.',
                ];
            }

            $subscription->markAsVerified();

            Log::info('Subscription verified', [
                'subscription_id' => $subscription->id,
                'email' => $subscription->email,
            ]);

            return [
                'success' => true,
                'message' => 'Email verified successfully. You will now receive price change notifications.',
                'data' => [
                    'subscription_id' => $subscription->id,
                    'listing_title' => $subscription->listing_title,
                ],
            ];

        } catch (Exception $e) {
            Log::error('Failed to verify subscription', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred during verification. Please try again.',
            ];
        }
    }

    /**
     * Get subscription statistics for user
     *
     * @param string $email
     * @param bool $includeHistory
     * @param int $limit
     * @return array
     */
    public function getSubscriptionStats(string $email, bool $includeHistory = false, int $limit = 10): array
    {
        try {
            $subscriptions = Subscription::where('email', $email)
                ->where('is_verified', true)
                ->with([
                    'priceHistories' => function ($query) use ($limit) {
                        $query->orderBy('checked_at', 'desc')->limit($limit);
                    }
                ])
                ->get();

            $stats = [
                'total_subscriptions' => $subscriptions->count(),
                'active_subscriptions' => 0,
                'total_price_checks' => 0,
                'subscriptions' => [],
            ];

            foreach ($subscriptions as $subscription) {
                $latestHistory = $subscription->latestPriceHistory();
                $isActive = $latestHistory && $latestHistory->is_available;

                if ($isActive) {
                    $stats['active_subscriptions']++;
                }

                $stats['total_price_checks'] += $subscription->priceHistories()->count();

                $subscriptionData = [
                    'id' => $subscription->id,
                    'listing_title' => $subscription->listing_title,
                    'listing_url' => $subscription->listing_url,
                    'created_at' => $subscription->created_at,
                    'is_active' => $isActive,
                    'current_price' => $latestHistory?->price,
                    'last_checked' => $latestHistory?->checked_at,
                    'price_changes_count' => $subscription->priceHistories()
                        ->whereNotNull('previous_price')
                        ->whereRaw('price != previous_price')
                        ->count(),
                ];

                if ($includeHistory) {
                    $subscriptionData['price_history'] = $subscription->priceHistories
                        ->map(function ($history) {
                            return [
                                'price' => $history->price,
                                'previous_price' => $history->previous_price,
                                'change' => $history->getFormattedPriceChange(),
                                'is_available' => $history->is_available,
                                'checked_at' => $history->checked_at,
                                'change_reason' => $history->change_reason,
                            ];
                        });
                }

                $stats['subscriptions'][] = $subscriptionData;
            }

            return $stats;

        } catch (Exception $e) {
            Log::error('Failed to get subscription stats', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Delete subscription
     *
     * @param int $subscriptionId
     * @param string $email
     * @return array
     */
    public function deleteSubscription(int $subscriptionId, string $email): array
    {
        try {
            $subscription = Subscription::where('id', $subscriptionId)
                ->where('email', $email)
                ->first();

            if (!$subscription) {
                return [
                    'success' => false,
                    'message' => 'Subscription not found.',
                ];
            }

            $subscription->delete();

            Log::info('Subscription deleted', [
                'subscription_id' => $subscriptionId,
                'email' => $email,
            ]);

            return [
                'success' => true,
                'message' => 'Subscription deleted successfully.',
            ];

        } catch (Exception $e) {
            Log::error('Failed to delete subscription', [
                'subscription_id' => $subscriptionId,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred while deleting subscription.',
            ];
        }
    }
}
