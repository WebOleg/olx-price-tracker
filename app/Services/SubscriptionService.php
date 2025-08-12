<?php

namespace App\Services;

use App\Models\EmailVerification;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionService
{
    private EmailService $emailService;
    private OlxParserService $olxParser;

    public function __construct(EmailService $emailService, OlxParserService $olxParser)
    {
        $this->emailService = $emailService;
        $this->olxParser = $olxParser;
    }

    /**
     * Create new subscription
     *
     * @param string $listingUrl
     * @param string $email
     * @return array
     */
    public function createSubscription(string $listingUrl, string $email): array
    {
        try {
            DB::beginTransaction();

            // Check if subscription already exists
            $existingSubscription = Subscription::where('listing_url', $listingUrl)
                ->where('email', $email)
                ->first();

            if ($existingSubscription) {
                if ($existingSubscription->is_verified) {
                    return [
                        'success' => false,
                        'message' => 'Ви вже підписані на це оголошення',
                        'subscription_id' => $existingSubscription->id
                    ];
                } else {
                    // Resend verification
                    $token = $existingSubscription->generateVerificationToken();
                    $existingSubscription->save();
                    
                    $this->emailService->sendVerificationEmail($email, $token);
                    
                    DB::commit();
                    return [
                        'success' => true,
                        'message' => 'Лист підтвердження відправлено повторно',
                        'subscription_id' => $existingSubscription->id
                    ];
                }
            }

            // Parse listing to get initial price
            $listingData = $this->olxParser->parseListingData($listingUrl);
            if (!$listingData) {
                return [
                    'success' => false,
                    'message' => 'Не вдається отримати дані оголошення. Перевірте посилання'
                ];
            }

            // Create subscription
            $subscription = new Subscription([
                'listing_url' => $listingUrl,
                'email' => $email,
                'current_price' => $listingData['price'] ?? null,
                'listing_title' => $listingData['title'] ?? null,
                'is_verified' => false,
                'is_active' => true,
                'last_checked_at' => now(),
            ]);

            $token = $subscription->generateVerificationToken();
            $subscription->save();

            // Send verification email
            $emailSent = $this->emailService->sendVerificationEmail($email, $token);
            
            if (!$emailSent) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'Помилка надсилання листа підтвердження'
                ];
            }

            DB::commit();
            return [
                'success' => true,
                'message' => 'Підписка створена. Перевірте пошту для підтвердження',
                'subscription_id' => $subscription->id
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Subscription creation failed', [
                'url' => $listingUrl,
                'email' => $email,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Помилка створення підписки'
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
        $subscription = Subscription::where('verification_token', $token)->first();

        if (!$subscription) {
            return [
                'success' => false,
                'message' => 'Невірний токен підтвердження'
            ];
        }

        if ($subscription->is_verified) {
            return [
                'success' => true,
                'message' => 'Email вже підтверджено'
            ];
        }

        $subscription->is_verified = true;
        $subscription->verification_token = null;
        $subscription->save();

        return [
            'success' => true,
            'message' => 'Email успішно підтверджено. Відстеження цін активовано!'
        ];
    }

    /**
     * Get subscription statistics
     *
     * @param string $email
     * @return array
     */
    public function getSubscriptionStats(string $email): array
    {
        $subscriptions = Subscription::where('email', $email)
            ->where('is_verified', true)
            ->with('priceHistories')
            ->get();

        return [
            'total_subscriptions' => $subscriptions->count(),
            'active_subscriptions' => $subscriptions->where('is_active', true)->count(),
            'subscriptions' => $subscriptions->map(function ($subscription) {
                return [
                    'id' => $subscription->id,
                    'url' => $subscription->listing_url,
                    'title' => $subscription->listing_title,
                    'current_price' => $subscription->current_price,
                    'last_checked' => $subscription->last_checked_at,
                    'price_changes' => $subscription->priceHistories->where('change_type', '!=', 'no_change')->count()
                ];
            })
        ];
    }
}
