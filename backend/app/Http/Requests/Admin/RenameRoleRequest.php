<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Zmiana nazwy wyświetlanej i/lub kodu roli. Kod ról systemowych jest zablokowany —
 * program, migracje i `permissions:sync` szukają ich po kodzie.
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
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'display_name')
                    ->where(fn ($q) => $q->where('guard_name', 'web')->where('name', '!=', $role)),
            ],
            // 32 = długość kolumny users.role, w której zapisany jest kod roli użytkownika.
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:32',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('roles', 'name')
                    ->where(fn ($q) => $q->where('guard_name', 'web')->where('name', '!=', $role)),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $role = (string) $this->route('role');

            if (! $this->has('display_name') && ! $this->has('name')) {
                $validator->errors()->add('display_name', 'Podaj nową nazwę albo kod roli.');
            }

            $newCode = $this->input('name');
            if (is_string($newCode) && $newCode !== $role && in_array($role, PermissionCatalog::ROLES, true)) {
                $validator->errors()->add('name', 'Kodu roli systemowej nie można zmienić — program odwołuje się do niego.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'display_name.required' => 'Podaj nazwę roli.',
            'display_name.unique' => 'Inna rola ma już taką nazwę.',
            'name.required' => 'Podaj kod roli.',
            'name.max' => 'Kod roli: najwyżej 32 znaki.',
            'name.regex' => 'Kod roli: małe litery, cyfry i myślniki (np. handel-krakow).',
            'name.unique' => 'Taki kod roli już istnieje.',
        ];
    }
}
