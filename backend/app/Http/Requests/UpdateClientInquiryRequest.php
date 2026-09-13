<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClientInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inquiries.use') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reply_subject' => ['sometimes', 'string', 'max:255'],
            'reply_body' => ['sometimes', 'string', 'max:20000'],
        ];
    }
}
