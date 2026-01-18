<?php

namespace App\Http\Requests\VirtualCard;

use Illuminate\Foundation\Http\FormRequest;

class WithdrawCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Amount is required.',
            'amount.min' => 'Minimum withdrawal amount is $1.',
        ];
    }
}
