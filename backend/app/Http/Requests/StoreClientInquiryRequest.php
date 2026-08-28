<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientInquiryRequest extends FormRequest
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
            'body' => ['required', 'string', 'min:20', 'max:20000'],
            'subject' => ['nullable', 'string', 'max:200'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'tone' => ['required', 'in:formal,handlowy'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.min' => 'Wklej treść zapytania (co najmniej 20 znaków).',
        ];
    }
}
