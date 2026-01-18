<?php

namespace App\Http\Requests\VirtualCard;

use Illuminate\Foundation\Http\FormRequest;

class FundCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:1',
            'payment_wallet_type' => 'required|string|in:naira_wallet,crypto_wallet',
            'payment_wallet_currency' => 'nullable|string|max:10',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Amount is required.',
            'amount.min' => 'Minimum funding amount is $1.',
            'payment_wallet_type.required' => 'Payment wallet type is required.',
        ];
    }
}
