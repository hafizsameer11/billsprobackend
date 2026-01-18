<?php

namespace App\Http\Requests\BillPayment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitiateBillPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'categoryCode' => 'required|string|exists:bill_payment_categories,code',
            'providerId' => 'required|integer|exists:bill_payment_providers,id',
            'currency' => 'required|string|max:10',
            'amount' => 'nullable|numeric|min:1',
            'planId' => 'nullable|integer|exists:bill_payment_plans,id',
            'accountNumber' => 'nullable|string|max:255',
            'beneficiaryId' => 'nullable|integer|exists:beneficiaries,id',
            'accountType' => 'nullable|string|in:prepaid,postpaid',
        ];
    }

    public function messages(): array
    {
        return [
            'categoryCode.required' => 'Category code is required.',
            'providerId.required' => 'Provider ID is required.',
            'currency.required' => 'Currency is required.',
        ];
    }
}
