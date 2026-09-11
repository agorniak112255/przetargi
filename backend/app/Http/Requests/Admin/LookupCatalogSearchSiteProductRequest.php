<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class LookupCatalogSearchSiteProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.access') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['required_without:product_id', 'nullable', 'string', 'min:2', 'max:160'],
            'product_id' => ['required_without:q', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.required_without' => 'Podaj SKU, nazwę albo wybierz produkt.',
            'q.min' => 'Wpisz co najmniej 2 znaki (SKU albo fragment nazwy).',
            'product_id.required_without' => 'Podaj SKU, nazwę albo wybierz produkt.',
        ];
    }
}
