<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
use App\Models\Subscription;

class CreateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
                        $fail("Максимум {$maxSubscriptions} підписок дозволено на один email.");
                    }
                },
            ],
            'listing_url' => [
                'required',
                'url',
                'max:500',
                'regex:/^https:\/\/(www\.)?olx\.ua\/.*\/obyavlenie\/.+/',
                function ($attribute, $value, $fail) {
                    $existingSubscription = Subscription::where('listing_url', $value)
                        ->where('email', $this->input('email'))
                        ->where('is_verified', true)
                        ->first();

                    if ($existingSubscription) {
                        $fail('Ви вже підписані на це оголошення.');
                    }

                    // Тільки для продакшену перевіряємо на тестові URL
                    if (app()->environment('production')) {
                        $suspiciousPatterns = [
                            'test',
                            'example',
                            'demo',
                            'fake',
                            'spam'
                        ];

                        $urlLower = strtolower($value);
                        foreach ($suspiciousPatterns as $pattern) {
                            if (strpos($urlLower, $pattern) !== false) {
                                $fail('Тестові або спам URL не дозволені.');
                                break;
                            }
                        }
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email обов\'язковий.',
            'email.email' => 'Введіть правильний email.',
            'email.max' => 'Email не повинен перевищувати 255 символів.',
            'listing_url.required' => 'URL оголошення OLX обов\'язковий.',
            'listing_url.url' => 'Введіть правильний URL.',
            'listing_url.max' => 'URL не повинен перевищувати 500 символів.',
            'listing_url.regex' => 'URL має бути дійсним посиланням на оголошення OLX (формат: https://olx.ua/*/obyavlenie/listing-id).',
        ];
    }

    public function attributes(): array
    {
        return [
            'email' => 'email адреса',
            'listing_url' => 'URL оголошення',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Помилка валідації',
                'errors' => $validator->errors(),
            ], 422)
        );
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->hasCleanUrl()) {
                $this->validateUrlAccessibility($validator);
            }
        });
    }

    protected function hasCleanUrl(): bool
    {
        $url = $this->input('listing_url');
        return !empty($url) && filter_var($url, FILTER_VALIDATE_URL);
    }

    protected function validateUrlAccessibility(Validator $validator): void
    {
        $url = $this->input('listing_url');

        if (!preg_match('/\/obyavlenie\/[^\/]+/', $url)) {
            $validator->errors()->add(
                'listing_url',
                'URL не схожий на правильне посилання OLX оголошення.'
            );
        }

        $suspiciousProtocols = ['javascript:', 'data:', 'file:'];
        foreach ($suspiciousProtocols as $protocol) {
            if (stripos($url, $protocol) !== false) {
                $validator->errors()->add(
                    'listing_url',
                    'URL містить недозволений протокол.'
                );
                break;
            }
        }
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim($this->email)),
            'listing_url' => trim($this->listing_url),
        ]);
    }
}
