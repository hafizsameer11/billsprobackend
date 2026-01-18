<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'message' => 'required|string|min:1',
            'attachment' => 'nullable|file|max:10240', // 10MB max
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'Message is required.',
            'attachment.max' => 'Attachment size must not exceed 10MB.',
        ];
    }
}
