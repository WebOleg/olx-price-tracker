<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubscriptionRequest;
use App\Models\PriceHistory;
use App\Models\Subscription;
use App\Services\PriceTrackerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class DashboardController extends Controller
{
    private PriceTrackerService $priceTrackerService;

    public function __construct(PriceTrackerService $priceTrackerService)
    {
        $this->priceTrackerService = $priceTrackerService;
    }

    /**
     * Display the main dashboard with subscriptions list
     */
    public function index(): View
    {
        $subscriptions = Subscription::with(['priceHistory' => function ($query) {
            $query->latest()->limit(1);
        }])
            ->latest()
            ->paginate(20);

        $stats = [
            'total' => Subscription::count(),
            'verified' => Subscription::where('is_verified', true)->count(),
            'active' => Subscription::where('is_verified', true)->whereHas('priceHistory', function ($query) {
                $query->where('is_available', true);
            })->count(),
        ];

        return view('dashboard', compact('subscriptions', 'stats'));
    }

    /**
     * Show subscription creation form
     */
    public function create(): View
    {
        return view('subscribe');
    }

    /**
     * Store a new subscription
     */
    public function store(SubscriptionRequest $request): RedirectResponse
    {
        try {
            $validated = $request->validated();

            // Check if subscription already exists
            $existingSubscription = Subscription::where('email', $validated['email'])
                ->where('listing_url', $validated['listing_url'])
                ->first();

            if ($existingSubscription) {
                if (!$existingSubscription->is_verified) {
                    return redirect()->back()
                        ->with('warning', 'You already have an unverified subscription for this listing. Please check your email to verify it.');
                }

                return redirect()->back()
                    ->with('warning', 'You are already subscribed to this listing.');
            }

            // Create new subscription
            $subscription = Subscription::create([
                'email' => $validated['email'],
                'listing_url' => $validated['listing_url'],
                'verification_token' => null,
                'is_verified' => false,
            ]);

            // Send verification email
            $this->priceTrackerService->sendVerificationEmail($subscription);

            Log::info('New subscription created', [
                'subscription_id' => $subscription->id,
                'email' => $subscription->email,
                'url' => $subscription->listing_url,
            ]);

            return redirect()->route('dashboard')
                ->with('success', 'Subscription created! Please check your email to verify the subscription.');

        } catch (Exception $e) {
            Log::error('Failed to create subscription', [
                'error' => $e->getMessage(),
                'email' => $request->input('email'),
                'url' => $request->input('listing_url'),
            ]);

            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to create subscription. Please try again.');
        }
    }

    /**
     * Verify email subscription
     */
    public function verify(Request $request, string $token): RedirectResponse
    {
        try {
            $subscription = Subscription::where('verification_token', $token)
                ->where('is_verified', false)
                ->first();

            if (!$subscription) {
                return redirect()->route('dashboard')
                    ->with('error', 'Invalid or expired verification token.');
            }

            $subscription->update([
                'is_verified' => true,
                'verified_at' => now(),
                'verification_token' => null,
            ]);

            // Trigger initial price check for this subscription
            try {
                $this->priceTrackerService->checkPriceForUrl($subscription->listing_url);
            } catch (Exception $e) {
                Log::warning('Failed to perform initial price check after verification', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('Subscription verified', [
                'subscription_id' => $subscription->id,
                'email' => $subscription->email,
            ]);

            return redirect()->route('dashboard')
                ->with('success', 'Email verified successfully! You will now receive price change notifications.');

        } catch (Exception $e) {
            Log::error('Failed to verify subscription', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('dashboard')
                ->with('error', 'Verification failed. Please try again.');
        }
    }

    /**
     * Delete a subscription
     */
    public function destroy(Request $request, Subscription $subscription): RedirectResponse
    {
        try {
            $email = $subscription->email;
            $url = $subscription->listing_url;

            $subscription->delete();

            Log::info('Subscription deleted', [
                'email' => $email,
                'url' => $url,
            ]);

            return redirect()->route('dashboard')
                ->with('success', 'Subscription deleted successfully.');

        } catch (Exception $e) {
            Log::error('Failed to delete subscription', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->with('error', 'Failed to delete subscription. Please try again.');
        }
    }

    /**
     * Resend verification email
     */
    public function resendVerification(Request $request, Subscription $subscription): RedirectResponse
    {
        if ($subscription->is_verified) {
            return redirect()->back()
                ->with('warning', 'This subscription is already verified.');
        }

        try {
            // Generate new verification token
            $subscription->update([
                'verification_token' => null,
            ]);

            $this->priceTrackerService->sendVerificationEmail($subscription);

            Log::info('Verification email resent', [
                'subscription_id' => $subscription->id,
                'email' => $subscription->email,
            ]);

            return redirect()->back()
                ->with('success', 'Verification email sent! Please check your inbox.');

        } catch (Exception $e) {
            Log::error('Failed to resend verification email', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->with('error', 'Failed to send verification email. Please try again.');
        }
    }
}
