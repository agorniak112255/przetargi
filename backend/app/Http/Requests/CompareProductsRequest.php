<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CompareProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.view') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('ids') && $this->filled('a') && $this->filled('b')) {
            $this->merge([
                'ids' => [$this->input('a'), $this->input('b')],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:2', 'max:5'],
            'ids.*' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'requirement' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return list<int>
     */
    public function productIds(): array
    {
        return array_values(array_map('intval', $this->validated('ids')));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.min' => 'Wybierz co najmniej 2 produkty.',
            'ids.max' => 'Możesz porównać maksymalnie 5 produktów.',
            'ids.*.distinct' => 'Każdy produkt można wybrać tylko raz.',
        ];
    }
}
