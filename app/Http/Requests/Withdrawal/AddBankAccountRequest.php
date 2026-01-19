<?php

namespace App\Http\Requests\Withdrawal;

use Illuminate\Foundation\Http\FormRequest;

class AddBankAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bank_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:50',
            'account_name' => 'required|string|max:255',
            'currency' => 'nullable|string|size:3',
            'country_code' => 'nullable|string|size:2',
            'metadata' => 'nullable|array',
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
            'bank_name.required' => 'Bank name is required.',
            'account_number.required' => 'Account number is required.',
            'account_name.required' => 'Account name is required.',
        ];
    }
}
