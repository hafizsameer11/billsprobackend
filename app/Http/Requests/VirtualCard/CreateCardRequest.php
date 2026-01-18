<?php

namespace App\Http\Requests\VirtualCard;

use Illuminate\Foundation\Http\FormRequest;

class CreateCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'card_name' => 'required|string|max:255',
            'card_color' => 'nullable|string|in:green,brown,purple',
            'card_type' => 'nullable|string|in:mastercard,visa',
            'payment_wallet_type' => 'required|string|in:naira_wallet,crypto_wallet',
            'payment_wallet_currency' => 'nullable|string|max:10',
            'billing_address_street' => 'nullable|string|max:255',
            'billing_address_city' => 'nullable|string|max:100',
            'billing_address_state' => 'nullable|string|max:100',
            'billing_address_country' => 'nullable|string|max:100',
            'billing_address_postal_code' => 'nullable|string|max:20',
            'daily_spending_limit' => 'nullable|numeric|min:0',
            'monthly_spending_limit' => 'nullable|numeric|min:0',
            'daily_transaction_limit' => 'nullable|integer|min:0',
            'monthly_transaction_limit' => 'nullable|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'card_name.required' => 'Card name is required.',
            'payment_wallet_type.required' => 'Payment wallet type is required.',
        ];
    }
}
