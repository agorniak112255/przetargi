<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachProductKitRequest extends FormRequest
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
            'related_product_ids' => ['required', 'array', 'min:1', 'max:40'],
            'related_product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
        ];
    }
}
