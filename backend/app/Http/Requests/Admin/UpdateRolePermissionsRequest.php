<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRolePermissionsRequest extends FormRequest
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
        return [
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
            // Uprawnienia, które strona pokazywała. Zapis zmienia tylko je — strona wczytana
            // przed dodaniem nowego uprawnienia nie może go odebrać, bo o nim nie wiedziała.
            'known' => ['required', 'array'],
            'known.*' => ['string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'known.required' => 'Strona jest nieaktualna — odśwież ją i zaznacz uprawnienia jeszcze raz.',
        ];
    }
}
