<?php

namespace App\Http\Requests\VirtualCard;

use Illuminate\Foundation\Http\FormRequest;

class CreateSpendControlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'description' => 'required|string|max:500',
            'type' => 'required|string|in:purchase,blockedMcc',
            'period' => 'required|string|in:daily,monthly,yearly',
            'limit' => 'required|numeric|min:0',
        ];
    }
}
