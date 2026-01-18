<?php

namespace App\Http\Requests\BillPayment;

use Illuminate\Foundation\Http\FormRequest;

class ValidateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'providerId' => 'required|integer|exists:bill_payment_providers,id',
            'accountNumber' => 'required|string|min:5',
        ];
    }

    public function messages(): array
    {
        return [
            'providerId.required' => 'Provider ID is required.',
            'accountNumber.required' => 'Account number is required.',
            'accountNumber.min' => 'Account number must be at least 5 characters.',
        ];
    }
}
