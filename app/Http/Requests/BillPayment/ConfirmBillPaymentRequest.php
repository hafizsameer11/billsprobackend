<?php

namespace App\Http\Requests\BillPayment;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmBillPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'transactionId' => 'required|integer|exists:transactions,id',
        ];
    }

    public function messages(): array
    {
        return [
            'transactionId.required' => 'Transaction ID is required.',
        ];
    }
}
