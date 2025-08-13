<?php

use App\Http\Controllers\Api\SubscriptionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Health check endpoint
Route::get('/health', [SubscriptionController::class, 'health'])
    ->name('api.health');

// V1 API routes
Route::prefix('v1')->group(function () {
    Route::post('/subscriptions', [SubscriptionController::class, 'store'])
        ->name('api.subscriptions.store');

    Route::get('/subscriptions/stats', [SubscriptionController::class, 'stats'])
        ->name('api.subscriptions.stats');

    Route::delete('/subscriptions/{subscription}', [SubscriptionController::class, 'destroy'])
        ->name('api.subscriptions.destroy');

    Route::get('/verify-email/{token}', [SubscriptionController::class, 'verify'])
        ->name('api.subscriptions.verify');
});

// Legacy routes (для зворотної сумісності)
Route::post('/subscriptions', [SubscriptionController::class, 'store']);
Route::get('/subscriptions/stats', [SubscriptionController::class, 'stats']);
Route::get('/verify-email/{token}', [SubscriptionController::class, 'verify']);

// Admin endpoints
Route::prefix('admin')->group(function () {
    Route::get('/stats', [SubscriptionController::class, 'globalStats'])
        ->name('api.admin.stats');

    Route::post('/trigger-price-check', [SubscriptionController::class, 'triggerPriceCheck'])
        ->name('api.admin.trigger-price-check');
});

// Fallback для невідомих API маршрутів
Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'API endpoint не знайдено',
        'error_code' => 'ENDPOINT_NOT_FOUND',
        'available_endpoints' => [
            'POST /api/v1/subscriptions' => 'Створити підписку',
            'GET /api/v1/subscriptions/stats' => 'Отримати статистику',
            'DELETE /api/v1/subscriptions/{id}' => 'Видалити підписку',
            'GET /api/v1/verify-email/{token}' => 'Підтвердити email',
            'GET /api/health' => 'Перевірка здоров\'я',
        ]
    ], 404);
});
