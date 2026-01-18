<?php

namespace App\Http\Requests\BillPayment;

use Illuminate\Foundation\Http\FormRequest;

class CreateBeneficiaryRequest extends FormRequest
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
            'name' => 'nullable|string|max:255',
            'accountNumber' => 'required|string|max:255',
            'accountType' => 'nullable|string|in:prepaid,postpaid',
        ];
    }

    public function messages(): array
    {
        return [
            'categoryCode.required' => 'Category code is required.',
            'providerId.required' => 'Provider ID is required.',
            'accountNumber.required' => 'Account number is required.',
        ];
    }
}
