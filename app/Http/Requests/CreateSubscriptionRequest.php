<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
use App\Models\Subscription;

class CreateSubscriptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email:rfc,dns',
                'max:255',
                function ($attribute, $value, $fail) {
                    $subscriptionCount = Subscription::where('email', $value)
                        ->where('is_verified', true)
                        ->count();

                    $maxSubscriptions = config('app.max_subscriptions_per_email', 10);

                    if ($subscriptionCount >= $maxSubscriptions) {
                        $fail("Maximum {$maxSubscriptions} subscriptions allowed per email address.");
                    }
                },
            ],
            'listing_url' => [
                'required',
                'url',
                'max:500',
                'regex:/^https:\/\/(www\.)?olx\.ua\//',
                function ($attribute, $value, $fail) {
                    $existingSubscription = Subscription::where('listing_url', $value)
                        ->where('email', $this->input('email'))
                        ->where('is_verified', true)
                        ->first();

                    if ($existingSubscription) {
                        $fail('You are already subscribed to this listing.');
                    }
                },
            ],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Email address is required.',
            'email.email' => 'Please provide a valid email address.',
            'email.max' => 'Email address must not exceed 255 characters.',
            'listing_url.required' => 'OLX listing URL is required.',
            'listing_url.url' => 'Please provide a valid URL.',
            'listing_url.max' => 'URL must not exceed 500 characters.',
            'listing_url.regex' => 'URL must be a valid OLX.ua listing URL.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'email' => 'email address',
            'listing_url' => 'listing URL',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422)
        );
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Additional validation logic if needed
            if ($this->hasCleanUrl()) {
                $this->validateUrlAccessibility($validator);
            }
        });
    }

    /**
     * Check if URL has clean format
     */
    protected function hasCleanUrl(): bool
    {
        $url = $this->input('listing_url');
        return !empty($url) && filter_var($url, FILTER_VALIDATE_URL);
    }

    /**
     * Validate URL accessibility (basic check)
     */
    protected function validateUrlAccessibility(Validator $validator): void
    {
        $url = $this->input('listing_url');

        // Extract listing ID from URL
        if (!preg_match('/\/obyavlenie\/[^\/]+/', $url)) {
            $validator->errors()->add(
                'listing_url',
                'URL does not appear to be a valid OLX listing URL format.'
            );
        }

        // Check for suspicious patterns
        $suspiciousPatterns = ['javascript:', 'data:', 'file:'];
        foreach ($suspiciousPatterns as $pattern) {
            if (stripos($url, $pattern) !== false) {
                $validator->errors()->add(
                    'listing_url',
                    'URL contains invalid protocol.'
                );
                break;
            }
        }
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim($this->email)),
            'listing_url' => trim($this->listing_url),
        ]);
    }
}
