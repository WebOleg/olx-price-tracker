<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetStatsRequest extends FormRequest
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
            'email' => 'required|email|max:255',
            'include_history' => 'sometimes|boolean',
            'limit' => 'sometimes|integer|min:1|max:100',
        ];
    }

    /**
     * Get validated data with defaults
     */
    public function validatedWithDefaults(): array
    {
        $validated = $this->validated();

        return [
            'email' => $validated['email'],
            'include_history' => $validated['include_history'] ?? false,
            'limit' => $validated['limit'] ?? 10,
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Email є обов\'язковим.',
            'email.email' => 'Введіть правильний email.',
            'email.max' => 'Email не повинен перевищувати 255 символів.',
            'include_history.boolean' => 'include_history має бути true або false.',
            'limit.integer' => 'limit має бути числом.',
            'limit.min' => 'limit має бути мінімум 1.',
            'limit.max' => 'limit має бути максимум 100.',
        ];
    }
}
