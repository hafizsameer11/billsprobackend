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
            'useremail' => 'nullable|email|max:255',
            'firstname' => 'required|string|max:100',
            'lastname' => 'required|string|max:100',
            'dob' => 'required|date_format:Y-m-d',
            'address1' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'country' => 'required|string|size:2',
            'phone' => 'required|string|max:20',
            'countrycode' => 'required|string|max:5',
            'postalcode' => 'required|string|max:20',
            'card_color' => 'nullable|string|in:green,brown,purple',
            'card_type' => 'nullable|string|in:mastercard,visa',
            'payment_wallet_type' => 'nullable|string|in:naira_wallet,crypto_wallet,provider_balance',
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
            'firstname.required' => 'First name is required.',
            'lastname.required' => 'Last name is required.',
            'dob.required' => 'Date of birth is required.',
            'address1.required' => 'Address line 1 is required.',
            'city.required' => 'City is required.',
            'state.required' => 'State is required.',
            'country.required' => 'Country is required.',
            'phone.required' => 'Phone is required.',
            'countrycode.required' => 'Country code is required.',
            'postalcode.required' => 'Postal code is required.',
        ];
    }
}
