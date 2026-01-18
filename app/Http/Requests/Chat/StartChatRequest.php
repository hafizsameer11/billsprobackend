<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class StartChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'issue_type' => 'required|string|in:fiat_issue,virtual_card_issue,crypto_issue,general',
            'message' => 'required|string|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'issue_type.required' => 'Issue type is required.',
            'issue_type.in' => 'Invalid issue type.',
            'message.required' => 'Message is required.',
        ];
    }
}
