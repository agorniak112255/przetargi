<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\BrandDictionaryEntry;
use App\Services\BrandDictionaryAdminService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBrandDictionaryEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.dictionaries.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'term' => ['bail', 'sometimes', 'required', 'string', 'max:120', StoreBrandDictionaryEntryRequest::termRule($this->entry()->id)],
            'kind' => ['sometimes', 'required', 'string', Rule::in(BrandDictionaryEntry::KINDS)],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:120'],
            'detect_in_query' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['kind', 'manufacturer'])) {
                return;
            }
            $entry = $this->entry();
            // Zmiana samego rodzaju na markę też musi mieć producenta: z ciała albo z istniejącego wiersza.
            // Istniejącego producenta sprawdzamy tylko wtedy, gdy przyszedł w ciele — inaczej edycja notatki
            // marki blokowałaby się po zniknięciu producenta z katalogu.
            $kind = $this->has('kind') ? (string) $this->input('kind') : $entry->kind;
            $sent = $this->has('manufacturer');
            $manufacturer = $sent ? $this->input('manufacturer') : $entry->manufacturer;
            $service = app(BrandDictionaryAdminService::class);
            $error = $sent || $kind !== $entry->kind
                ? $service->manufacturerError($kind, $manufacturer)
                : null;
            if ($error !== null) {
                $validator->errors()->add('manufacturer', $error);
            }
            // Producenta sprawdzamy tylko przy zmianie słowa albo rodzaju — edycja notatki czy przełącznika
            // nie może się blokować, gdy producent w międzyczasie zniknął z katalogu.
            if (($this->has('term') || $this->has('kind')) && ! $validator->errors()->has('term')) {
                $term = $this->has('term') ? $this->input('term') : $entry->term;
                $termError = $service->producerTermError($kind, $term);
                if ($termError !== null) {
                    $validator->errors()->add('term', $termError);
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return StoreBrandDictionaryEntryRequest::sharedMessages() + [
            'term.required' => 'Słowo nie może być puste.',
            'kind.required' => 'Wybierz rodzaj wpisu.',
        ];
    }

    private function entry(): BrandDictionaryEntry
    {
        /** @var BrandDictionaryEntry $entry */
        $entry = $this->route('entry');

        return $entry;
    }
}
