<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResendOtpRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Public endpoint
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'nullable',
                'required_if:type,email',
                'email',
            ],
            'phone_number' => [
                'nullable',
                'required_if:type,phone',
                'string',
            ],
            'type' => [
                'required',
                Rule::in(['email', 'phone']),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'OTP type is required.',
            'type.in' => 'OTP type must be either email or phone.',
            'email.required_if' => 'Email is required when type is email.',
            'email.email' => 'Please provide a valid email address.',
            'phone_number.required_if' => 'Phone number is required when type is phone.',
        ];
    }
}
