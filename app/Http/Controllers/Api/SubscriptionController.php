<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SubscriptionController extends Controller
{
    private SubscriptionService $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    /**
     * Create new price subscription
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'listing_url' => [
                'required',
                'string',
                'url',
                'regex:/^https?:\/\/.*olx\.ua\/.*/'
            ],
            'email' => 'required|email|max:255'
        ], [
            'listing_url.required' => 'Посилання на оголошення обов\'язкове',
            'listing_url.url' => 'Невірний формат посилання',
            'listing_url.regex' => 'Підтримуються лише посилання OLX.ua',
            'email.required' => 'Email обов\'язковий',
            'email.email' => 'Невірний формат email'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Помилки валідації',
                'errors' => $validator->errors()
            ], 422);
        }

        $result = $this->subscriptionService->createSubscription(
            $request->input('listing_url'),
            $request->input('email')
        );

        $statusCode = $result['success'] ? 201 : 400;

        return response()->json($result, $statusCode);
    }

    /**
     * Verify email subscription
     *
     * @param string $token
     * @return JsonResponse
     */
    public function verify(string $token): JsonResponse
    {
        if (empty($token)) {
            return response()->json([
                'success' => false,
                'message' => 'Токен підтвердження відсутній'
            ], 400);
        }

        $result = $this->subscriptionService->verifySubscription($token);
        $statusCode = $result['success'] ? 200 : 400;

        return response()->json($result, $statusCode);
    }

    /**
     * Get user subscription statistics
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function stats(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255'
        ], [
            'email.required' => 'Email обов\'язковий',
            'email.email' => 'Невірний формат email'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Помилки валідації',
                'errors' => $validator->errors()
            ], 422);
        }

        $stats = $this->subscriptionService->getSubscriptionStats($request->input('email'));

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Health check endpoint
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'OLX Price Tracker API is running',
            'timestamp' => now()->toISOString(),
            'version' => '1.0.0'
        ]);
    }
}
