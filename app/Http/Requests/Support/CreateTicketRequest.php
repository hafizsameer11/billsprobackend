<?php

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

class CreateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'subject' => 'required|string|max:255',
            'description' => 'required|string',
            'issue_type' => 'nullable|string|in:fiat_issue,virtual_card_issue,crypto_issue,general',
            'priority' => 'nullable|string|in:low,medium,high,urgent',
        ];
    }

    public function messages(): array
    {
        return [
            'subject.required' => 'Subject is required.',
            'description.required' => 'Description is required.',
            'issue_type.in' => 'Invalid issue type.',
            'priority.in' => 'Invalid priority level.',
        ];
    }
}
