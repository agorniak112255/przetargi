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
            // Pochodzenie zapytania — wypełnia je dodatek do Thunderbirda.
            'source_channel' => ['nullable', 'in:web,thunderbird'],
            'source_message_id' => ['nullable', 'string', 'max:255'],
            // świadome założenie własnego zapytania mimo ostrzeżenia o duplikacie
            'force' => ['nullable', 'boolean'],
            // Pełny nagłówek From, np. „Jan Kowalski <jan@firma.pl>” — backend
            // sam rozbija go na nazwę i adres.
            'source_from' => ['nullable', 'string', 'max:400'],
            // Data wysłania maila (ISO 8601 albo RFC 2822).
            'source_sent_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.min' => 'Wklej treść zapytania (co najmniej 20 znaków).',
            'source_from.max' => 'Nagłówek nadawcy może mieć najwyżej 400 znaków.',
            'source_sent_at.date' => 'Data wysłania maila jest nieczytelna.',
        ];
    }
}
