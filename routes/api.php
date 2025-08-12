<?php

use App\Http\Controllers\Api\SubscriptionController;
use Illuminate\Support\Facades\Route;

// Health check
Route::get('/health', [SubscriptionController::class, 'health']);

// Subscription routes
Route::prefix('subscriptions')->group(function () {
    Route::post('/', [SubscriptionController::class, 'store']);
    Route::get('/stats', [SubscriptionController::class, 'stats']);
});

// Email verification
Route::get('/verify-email/{token}', [SubscriptionController::class, 'verify']);
