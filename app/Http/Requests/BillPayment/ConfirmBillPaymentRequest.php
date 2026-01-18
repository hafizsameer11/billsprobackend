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
            'pin' => 'required|string|size:4|regex:/^[0-9]{4}$/',
        ];
    }

    public function messages(): array
    {
        return [
            'transactionId.required' => 'Transaction ID is required.',
            'pin.required' => 'PIN is required.',
            'pin.size' => 'PIN must be exactly 4 digits.',
        ];
    }
}
