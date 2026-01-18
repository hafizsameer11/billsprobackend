<?php

namespace App\Http\Requests\BillPayment;

use Illuminate\Foundation\Http\FormRequest;

class ValidateMeterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'providerId' => 'required|integer|exists:bill_payment_providers,id',
            'meterNumber' => 'required|string|min:8',
            'accountType' => 'required|string|in:prepaid,postpaid',
        ];
    }

    public function messages(): array
    {
        return [
            'providerId.required' => 'Provider ID is required.',
            'meterNumber.required' => 'Meter number is required.',
            'meterNumber.min' => 'Meter number must be at least 8 characters.',
            'accountType.required' => 'Account type is required.',
        ];
    }
}
