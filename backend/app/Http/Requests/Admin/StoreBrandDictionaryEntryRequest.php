<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\BrandDictionaryEntry;
use App\Services\BrandDictionaryAdminService;
use App\Support\BrandDictionary;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBrandDictionaryEntryRequest extends FormRequest
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
            'term' => ['bail', 'required', 'string', 'max:120', self::termRule(null)],
            'kind' => ['required', 'string', Rule::in(BrandDictionaryEntry::KINDS)],
            'manufacturer' => ['nullable', 'string', 'max:120'],
            'detect_in_query' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['kind', 'manufacturer'])) {
                return;
            }
            $service = app(BrandDictionaryAdminService::class);
            $error = $service->manufacturerError((string) $this->input('kind'), $this->input('manufacturer'));
            if ($error !== null) {
                $validator->errors()->add('manufacturer', $error);
            }
            if (! $validator->errors()->has('term')) {
                $termError = $service->producerTermError((string) $this->input('kind'), $this->input('term'));
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
        return self::sharedMessages() + [
            'term.required' => 'Podaj słowo.',
            'kind.required' => 'Wybierz rodzaj wpisu.',
        ];
    }

    /**
     * Słowo musi mieć niepusty klucz (samo „™” nic nie rozpozna) i nie może powtarzać klucza innego wpisu —
     * „PELTOR” i „Peltor” to w wyszukiwarce to samo słowo.
     */
    public static function termRule(?int $ignoreId): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($ignoreId): void {
            $key = BrandDictionary::key((string) $value);
            if ($key === '') {
                $fail('Słowo musi zawierać literę albo cyfrę.');

                return;
            }
            if (app(BrandDictionaryAdminService::class)->termTaken($key, $ignoreId)) {
                $fail(BrandDictionaryAdminService::MESSAGE_TERM_TAKEN);
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public static function sharedMessages(): array
    {
        return [
            'term.string' => 'Słowo musi być tekstem.',
            'term.max' => 'Słowo może mieć najwyżej 120 znaków.',
            'kind.in' => 'Nieznany rodzaj wpisu.',
            'manufacturer.string' => 'Producent musi być tekstem.',
            'manufacturer.max' => 'Nazwa producenta może mieć najwyżej 120 znaków.',
            'detect_in_query.boolean' => 'Pole „rozpoznawaj w zapytaniu” musi być prawdą albo fałszem.',
            'note.string' => 'Notatka musi być tekstem.',
            'note.max' => 'Notatka może mieć najwyżej 200 znaków.',
        ];
    }
}
