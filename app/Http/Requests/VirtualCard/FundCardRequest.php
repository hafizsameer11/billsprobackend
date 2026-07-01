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
            'email' => 'nullable|email|max:255',
            'useremail' => 'nullable|email|max:255',
            'amount' => 'required|numeric|min:0.01',
            'payment_wallet_type' => 'required|string|in:naira_wallet,crypto_wallet',
            'payment_wallet_currency' => 'nullable|string|max:10',
            'pin' => 'required|string|size:4',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Amount is required.',
            'amount.min' => 'Minimum funding amount is $0.01.',
            'payment_wallet_type.required' => 'Select Naira wallet or Crypto wallet to pay for this load.',
        ];
    }
}
