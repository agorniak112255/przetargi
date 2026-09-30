<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ClientInquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientInquiryRequest extends FormRequest
{
    /**
     * Najdłuższa treść zapytania. Do 30.09.2026 było 20 000 — za mało na pismo przetargowe z załącznika
     * (opis odzieży ochronnej z formularzem ofertowym: 32 tys. znaków). 60 000 znaków to przy ostrożnym
     * liczeniu klienta modelu (1,8 znaku na token) ok. 33 tys. tokenów — z zapasem na odpowiedź przy
     * 50 pozycjach mieści się w oknie 65 536 tokenów bez przycinania treści.
     */
    public const MAX_BODY_CHARS = 60000;

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
            'body' => ['required', 'string', 'min:20', 'max:'.self::MAX_BODY_CHARS],
            'subject' => ['nullable', 'string', 'max:200'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            // szablon listu do klienta — lista w ClientInquiry::TONES
            'tone' => ['required', Rule::in(ClientInquiry::TONES)],
            // Pochodzenie zapytania — wypełnia je dodatek do Thunderbirda; „file” = tekst wczytany z pliku.
            'source_channel' => ['nullable', 'in:web,thunderbird,file'],
            // nazwa pliku, z którego pochodzi treść (tylko do śladu pochodzenia)
            'source_file_name' => ['nullable', 'string', 'max:255'],
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
            'body.max' => 'Treść zapytania może mieć najwyżej 60 000 znaków — usuń fragmenty, które nie dotyczą zamawianych wyrobów.',
            'tone.in' => 'Nieznany szablon listu.',
            'source_from.max' => 'Nagłówek nadawcy może mieć najwyżej 400 znaków.',
            'source_sent_at.date' => 'Data wysłania maila jest nieczytelna.',
        ];
    }
}
