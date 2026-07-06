<?php

namespace App\Http\Requests\Withdrawal;

use Illuminate\Foundation\Http\FormRequest;

class WithdrawRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bank_account_id' => 'required|integer|exists:bank_accounts,id',
            'amount' => 'required|numeric|min:1',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bank_account_id.required' => 'Bank account is required.',
            'bank_account_id.exists' => 'Selected bank account does not exist.',
            'amount.required' => 'Withdrawal amount is required.',
            'amount.min' => 'Withdrawal amount must be at least 1.',
        ];
    }
}
