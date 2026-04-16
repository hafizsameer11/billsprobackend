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
            'card_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'useremail' => 'nullable|email|max:255',
            'firstname' => 'nullable|string|max:100',
            'lastname' => 'nullable|string|max:100',
            'dob' => 'nullable|date_format:Y-m-d',
            'address1' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|size:2',
            'phone' => 'nullable|string|max:20',
            'countrycode' => 'nullable|string|max:5',
            'postalcode' => 'nullable|string|max:20',
            'card_color' => 'nullable|string|in:green,black,purple,red,blue,brown',
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
            'payment_wallet_type.required' => 'Select whether to pay from Naira or Crypto wallet.',
        ];
    }
}
