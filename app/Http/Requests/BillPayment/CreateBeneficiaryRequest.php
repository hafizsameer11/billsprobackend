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
            'categoryCode' => 'required|string|max:50',
            'providerId' => 'nullable|integer',
            'providerCode' => 'nullable|string|max:100',
            'providerName' => 'nullable|string|max:255',
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
