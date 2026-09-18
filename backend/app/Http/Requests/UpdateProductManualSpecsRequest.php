<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductManualSpecsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'specs' => ['present', 'array', 'max:'.Product::MANUAL_SPECS_MAX_ROWS],
            'specs.*.label' => ['required', 'string', 'max:120'],
            'specs.*.value' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'specs.max' => 'Najwyżej '.Product::MANUAL_SPECS_MAX_ROWS.' wierszy parametrów.',
            'specs.*.label.required' => 'Każdy wiersz ma nazwę parametru, np. „Waga”.',
            'specs.*.value.required' => 'Każdy wiersz ma wartość, np. „1,2 kg”.',
        ];
    }
}
