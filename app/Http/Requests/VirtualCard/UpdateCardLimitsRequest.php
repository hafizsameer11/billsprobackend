<?php

namespace App\Http\Requests\VirtualCard;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCardLimitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'daily_spending_limit' => 'nullable|numeric|min:0',
            'monthly_spending_limit' => 'nullable|numeric|min:0',
            'daily_transaction_limit' => 'nullable|integer|min:0',
            'monthly_transaction_limit' => 'nullable|integer|min:0',
        ];
    }
}
