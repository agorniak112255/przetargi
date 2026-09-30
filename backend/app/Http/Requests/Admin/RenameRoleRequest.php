<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Zmiana samej nazwy wyświetlanej roli. Kod roli (`name`) zostaje — na nim
 * opierają się przypisania użytkowników i role systemowe w kodzie.
 */
class RenameRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.roles.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $role = (string) $this->route('role');

        return [
            'display_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'display_name')
                    ->where(fn ($q) => $q->where('guard_name', 'web')->where('name', '!=', $role)),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'display_name.required' => 'Podaj nazwę roli.',
            'display_name.unique' => 'Inna rola ma już taką nazwę.',
        ];
    }
}
