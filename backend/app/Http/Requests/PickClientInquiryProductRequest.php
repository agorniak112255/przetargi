<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PickClientInquiryProductRequest extends FormRequest
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
            'item_id' => ['required', 'string', 'max:64'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            // Wstawienie wyrobu przepisuje list, więc niezapisany dopisek i warunki
            // jadą razem z nim — tak samo jak przy kliknięciu alternatywy.
            'extra_note' => ['nullable', 'string', 'max:1000'],
            'terms' => ['sometimes', 'array'],
            'terms.lead_time' => ['nullable', 'string', 'max:200'],
            'terms.delivery' => ['nullable', 'string', 'max:200'],
            'terms.payment' => ['nullable', 'string', 'max:200'],
            'terms.validity' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'item_id.required' => 'Brak pozycji, do której ma trafić wyrób.',
            'product_id.required' => 'Brak wyrobu do wstawienia.',
            'product_id.exists' => 'Nie ma takiego wyrobu w katalogu.',
        ];
    }
}
