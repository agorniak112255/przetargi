<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AssignCatalogSearchSiteManufacturerRequest extends FormRequest
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
            'manufacturer' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'manufacturer.required' => 'Podaj nazwę producenta, np. ARTRA.',
        ];
    }
}
