<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductSubstituteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('substitutes.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'main_product_id' => ['required', 'integer', 'exists:products,id'],
            'substitute_product_id' => [
                'required',
                'integer',
                'exists:products,id',
                'different:main_product_id',
                Rule::unique('product_substitutes', 'substitute_product_id')
                    ->where(fn ($q) => $q->where('main_product_id', $this->integer('main_product_id'))),
            ],
            'type' => ['required', 'in:preferowany,tanszy,premium,awaryjny'],
            'match_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'norms_ok' => ['sometimes', 'boolean'],
            'certs_ok' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'substitute_product_id.different' => 'Zamiennik musi być inny niż produkt główny.',
            'substitute_product_id.unique' => 'Taka para produkt główny → zamiennik już istnieje.',
        ];
    }
}
