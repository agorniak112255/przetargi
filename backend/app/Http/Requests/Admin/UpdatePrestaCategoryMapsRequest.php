<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePrestaCategoryMapsRequest extends FormRequest
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
            'maps' => ['required', 'array', 'max:2000'],
            'maps.*.local_category' => ['required', 'string', 'max:255'],
            'maps.*.presta_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
