<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DetachProductKitRequest extends FormRequest
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
            'all' => ['sometimes', 'boolean'],
            'accessory_ids' => ['sometimes', 'array', 'max:80'],
            'accessory_ids.*' => ['integer', 'distinct', 'exists:product_accessories,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('all')) {
                return;
            }
            $ids = $this->input('accessory_ids');
            if (is_array($ids) && $ids !== []) {
                return;
            }
            $validator->errors()->add('accessory_ids', 'Wskaż pozycje zestawu albo usuń wszystkie.');
        });
    }
}
