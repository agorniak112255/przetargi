<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\CatalogHostPriority;
use Illuminate\Foundation\Http\FormRequest;

class SetCatalogSearchSitePriorityRequest extends FormRequest
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
            'priority' => ['required', 'integer', 'between:'.CatalogHostPriority::MIN.','.CatalogHostPriority::MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'priority.required' => 'Podaj rangę od '.CatalogHostPriority::MIN.' do '.CatalogHostPriority::MAX.'.',
            'priority.integer' => 'Ranga to liczba całkowita od '.CatalogHostPriority::MIN.' do '.CatalogHostPriority::MAX.'.',
            'priority.between' => 'Ranga mieści się w zakresie '.CatalogHostPriority::MIN.'–'.CatalogHostPriority::MAX.'.',
        ];
    }
}
