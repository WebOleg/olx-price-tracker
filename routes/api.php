<?php

use App\Http\Controllers\Api\SubscriptionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;



// Health check endpoint - no middleware
Route::get('/health', [SubscriptionController::class, 'health'])
    ->name('api.health');

// Public API routes
Route::prefix('v1')->group(function () {

    // Subscription management
    Route::post('/subscriptions', [SubscriptionController::class, 'store'])
        ->middleware(['throttle:10,1']) // 10 requests per minute
        ->name('api.subscriptions.store');

    // Email verification
    Route::get('/verify-email/{token}', [SubscriptionController::class, 'verify'])
        ->middleware(['throttle:5,1']) // 5 requests per minute
        ->name('api.subscriptions.verify');

    // User statistics
    Route::get('/subscriptions/stats', [SubscriptionController::class, 'stats'])
        ->middleware(['throttle:20,1']) // 20 requests per minute
        ->name('api.subscriptions.stats');

    // Delete subscription
    Route::delete('/subscriptions/{subscription}', [SubscriptionController::class, 'destroy'])
        ->middleware(['throttle:10,1']) // 10 requests per minute
        ->name('api.subscriptions.destroy');
});

// Admin/Management routes (could add auth middleware)
Route::prefix('admin')->group(function () {

    // Global statistics
    Route::get('/stats', [SubscriptionController::class, 'globalStats'])
        ->middleware(['throttle:60,1']) // 60 requests per minute
        ->name('api.admin.stats');

    // Trigger manual price check
    Route::post('/trigger-price-check', [SubscriptionController::class, 'triggerPriceCheck'])
        ->middleware(['throttle:5,1']) // 5 requests per minute
        ->name('api.admin.trigger-price-check');
});

// Legacy support (redirect old routes)
Route::group(['prefix' => ''], function () {
    Route::post('/subscriptions', [SubscriptionController::class, 'store'])
        ->middleware(['throttle:10,1']);

    Route::get('/verify-email/{token}', [SubscriptionController::class, 'verify'])
        ->middleware(['throttle:5,1']);

    Route::get('/subscriptions/stats', [SubscriptionController::class, 'stats'])
        ->middleware(['throttle:20,1']);
});

// Catch-all route for API documentation or 404
Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'API endpoint not found',
        'documentation' => url('/api/docs'), // Could point to API docs
        'available_endpoints' => [
            'POST /api/v1/subscriptions' => 'Create subscription',
            'GET /api/v1/verify-email/{token}' => 'Verify email',
            'GET /api/v1/subscriptions/stats' => 'Get user stats',
            'DELETE /api/v1/subscriptions/{id}' => 'Delete subscription',
            'GET /api/health' => 'Health check',
        ],
    ], 404);
});
