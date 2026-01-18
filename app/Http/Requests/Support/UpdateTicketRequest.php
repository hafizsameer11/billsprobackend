<?php

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'subject' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'status' => 'sometimes|string|in:open,in_progress,resolved,closed',
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Invalid status.',
        ];
    }
}
