<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

Route::get('/subscribe', [DashboardController::class, 'create'])->name('subscribe');
Route::post('/subscriptions', [DashboardController::class, 'store'])->name('subscriptions.store');

Route::get('/verify/{token}', [DashboardController::class, 'verify'])->name('subscriptions.verify')->where('token', '[a-f0-9]{64}');
Route::post('/subscriptions/{subscription}/resend', [DashboardController::class, 'resendVerification'])->name('subscriptions.resend-verification');
Route::delete('/subscriptions/{subscription}', [DashboardController::class, 'destroy'])->name('subscriptions.destroy');
