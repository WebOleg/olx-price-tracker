<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateSubscriptionRequest;
use App\Http\Requests\GetStatsRequest;
use App\Services\SubscriptionService;
use App\Services\PriceTrackerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class SubscriptionController extends Controller
{
    private SubscriptionService $subscriptionService;
    private PriceTrackerService $priceTrackerService;

    public function __construct(
        SubscriptionService $subscriptionService,
        PriceTrackerService $priceTrackerService
    ) {
        $this->subscriptionService = $subscriptionService;
        $this->priceTrackerService = $priceTrackerService;
    }

    /**
     * Create new price subscription with enhanced validation
     *
     * @param CreateSubscriptionRequest $request
     * @return JsonResponse
     */
    public function store(CreateSubscriptionRequest $request): JsonResponse
    {
        try {
            $result = $this->subscriptionService->createSubscription(
                $request->validated()['listing_url'],
                $request->validated()['email']
            );

            $statusCode = $result['success'] ? 201 : 422;

            Log::info('Subscription creation attempt', [
                'email' => $request->validated()['email'],
                'url' => $request->validated()['listing_url'],
                'success' => $result['success'],
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json($result, $statusCode);

        } catch (Exception $e) {
            Log::error('Failed to create subscription in controller', [
                'email' => $request->validated()['email'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred. Please try again later.',
                'error_code' => 'SUBSCRIPTION_CREATE_ERROR',
            ], 500);
        }
    }

    /**
     * Verify email subscription with enhanced security
     *
     * @param string $token
     * @return JsonResponse
     */
    public function verify(string $token): JsonResponse
    {
        try {
            // Enhanced token validation
            if (empty($token) || strlen($token) !== 64 || !ctype_xdigit($token)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid verification token format.',
                    'error_code' => 'INVALID_TOKEN_FORMAT',
                ], 400);
            }

            $result = $this->subscriptionService->verifySubscription($token);
            $statusCode = $result['success'] ? 200 : 400;

            Log::info('Email verification attempt', [
                'token_prefix' => substr($token, 0, 8) . '...',
                'success' => $result['success'],
            ]);

            return response()->json($result, $statusCode);

        } catch (Exception $e) {
            Log::error('Failed to verify subscription in controller', [
                'token_prefix' => substr($token, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred during verification.',
                'error_code' => 'VERIFICATION_ERROR',
            ], 500);
        }
    }

    /**
     * Get user subscription statistics with enhanced data
     *
     * @param GetStatsRequest $request
     * @return JsonResponse
     */
    public function stats(GetStatsRequest $request): JsonResponse
    {
        try {
            $validated = $request->validatedWithDefaults();

            $stats = $this->subscriptionService->getSubscriptionStats(
                $validated['email'],
                $validated['include_history'],
                $validated['limit']
            );

            return response()->json([
                'success' => true,
                'data' => $stats,
                'meta' => [
                    'email' => $validated['email'],
                    'include_history' => $validated['include_history'],
                    'limit' => $validated['limit'],
                    'generated_at' => now()->toISOString(),
                ],
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get subscription stats in controller', [
                'email' => $request->validated()['email'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to retrieve subscription statistics.',
                'error_code' => 'STATS_RETRIEVAL_ERROR',
            ], 500);
        }
    }

    /**
     * Delete user subscription
     *
     * @param Request $request
     * @param int $subscriptionId
     * @return JsonResponse
     */
    public function destroy(Request $request, int $subscriptionId): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        try {
            $result = $this->subscriptionService->deleteSubscription(
                $subscriptionId,
                $request->input('email')
            );

            $statusCode = $result['success'] ? 200 : 404;

            Log::info('Subscription deletion attempt', [
                'subscription_id' => $subscriptionId,
                'email' => $request->input('email'),
                'success' => $result['success'],
            ]);

            return response()->json($result, $statusCode);

        } catch (Exception $e) {
            Log::error('Failed to delete subscription in controller', [
                'subscription_id' => $subscriptionId,
                'email' => $request->input('email'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while deleting subscription.',
                'error_code' => 'SUBSCRIPTION_DELETE_ERROR',
            ], 500);
        }
    }

    /**
     * Get global tracking statistics (admin endpoint)
     *
     * @return JsonResponse
     */
    public function globalStats(): JsonResponse
    {
        try {
            $stats = $this->priceTrackerService->getTrackingStatistics();

            return response()->json([
                'success' => true,
                'data' => $stats,
                'meta' => [
                    'generated_at' => now()->toISOString(),
                ],
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get global stats in controller', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to retrieve global statistics.',
                'error_code' => 'GLOBAL_STATS_ERROR',
            ], 500);
        }
    }

    /**
     * Health check endpoint with enhanced diagnostics
     *
     * @return JsonResponse
     */
    public function health(): JsonResponse
    {
        try {
            $health = [
                'status' => 'healthy',
                'service' => 'OLX Price Tracker API',
                'version' => '1.0.0',
                'timestamp' => now()->toISOString(),
                'environment' => config('app.env'),
                'checks' => [
                    'database' => $this->checkDatabaseHealth(),
                    'redis' => $this->checkRedisHealth(),
                    'storage' => $this->checkStorageHealth(),
                ],
            ];

            // Determine overall status
            $allChecksHealthy = collect($health['checks'])->every(fn($check) => $check['status'] === 'healthy');
            $health['status'] = $allChecksHealthy ? 'healthy' : 'degraded';

            $statusCode = $allChecksHealthy ? 200 : 503;

            return response()->json($health, $statusCode);

        } catch (Exception $e) {
            Log::error('Health check failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'unhealthy',
                'service' => 'OLX Price Tracker API',
                'timestamp' => now()->toISOString(),
                'error' => 'Health check failed',
            ], 503);
        }
    }

    /**
     * Trigger manual price check (admin endpoint)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function triggerPriceCheck(Request $request): JsonResponse
    {
        $request->validate([
            'url' => 'sometimes|url|regex:/^https:\/\/(www\.)?olx\.ua\//',
        ]);

        try {
            if ($request->has('url')) {
                // Check specific URL
                $result = $this->priceTrackerService->checkPriceForUrl($request->input('url'));
                $message = 'Price check completed for specific URL';
            } else {
                // Check all URLs
                $result = $this->priceTrackerService->checkAllPrices();
                $message = 'Price check completed for all subscriptions';
            }

            Log::info('Manual price check triggered', [
                'url' => $request->input('url'),
                'result' => $result,
            ]);

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $result,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to trigger price check', [
                'url' => $request->input('url'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to trigger price check.',
                'error_code' => 'PRICE_CHECK_ERROR',
            ], 500);
        }
    }

    /**
     * Check database health
     *
     * @return array
     */
    private function checkDatabaseHealth(): array
    {
        try {
            \DB::connection()->getPdo();
            return ['status' => 'healthy', 'message' => 'Database connection successful'];
        } catch (Exception $e) {
            return ['status' => 'unhealthy', 'message' => 'Database connection failed'];
        }
    }

    /**
     * Check Redis health
     *
     * @return array
     */
    private function checkRedisHealth(): array
    {
        try {
            if (config('database.redis.default.host') === null) {
                return ['status' => 'healthy', 'message' => 'Redis not configured (using database)'];
            }

            \Illuminate\Support\Facades\Redis::ping();
            return ['status' => 'healthy', 'message' => 'Redis connection successful'];
        } catch (Exception $e) {
            return ['status' => 'healthy', 'message' => 'Redis not available (using database fallback)'];
        }
    }

    /**
     * Check storage health
     *
     * @return array
     */
    private function checkStorageHealth(): array
    {
        try {
            $testFile = 'health-check-' . time() . '.txt';
            \Storage::put($testFile, 'health check');
            \Storage::delete($testFile);
            return ['status' => 'healthy', 'message' => 'Storage read/write successful'];
        } catch (Exception $e) {
            return ['status' => 'unhealthy', 'message' => 'Storage read/write failed'];
        }
    }
}
