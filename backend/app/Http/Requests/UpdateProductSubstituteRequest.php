<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ProductSubstitute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductSubstituteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('substitutes.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        /** @var ProductSubstitute $substitute */
        $substitute = $this->route('productSubstitute');

        $this->merge([
            'main_product_id' => $this->input('main_product_id', $substitute->main_product_id),
            'substitute_product_id' => $this->input('substitute_product_id', $substitute->substitute_product_id),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var ProductSubstitute $substitute */
        $substitute = $this->route('productSubstitute');
        $mainId = (int) $this->input('main_product_id');

        return [
            'main_product_id' => ['required', 'integer', 'exists:products,id'],
            'substitute_product_id' => [
                'required',
                'integer',
                'exists:products,id',
                'different:main_product_id',
                Rule::unique('product_substitutes', 'substitute_product_id')
                    ->ignore($substitute->id)
                    ->where(fn ($q) => $q->where('main_product_id', $mainId)),
            ],
            'type' => ['sometimes', 'required', 'in:preferowany,tanszy,premium,awaryjny'],
            'match_percent' => ['sometimes', 'required', 'integer', 'min:0', 'max:100'],
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
