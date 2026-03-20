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
            'useremail' => 'nullable|email|max:255',
            'amount' => 'required|numeric|min:0.01',
            'payment_wallet_type' => 'nullable|string|in:naira_wallet,crypto_wallet,provider_balance',
            'payment_wallet_currency' => 'nullable|string|max:10',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Amount is required.',
            'amount.min' => 'Minimum funding amount is $0.01.',
        ];
    }
}
