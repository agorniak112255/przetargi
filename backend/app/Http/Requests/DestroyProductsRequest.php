<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DestroyProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.delete') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array', 'min:1', 'max:200'],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
        ];
    }
}
