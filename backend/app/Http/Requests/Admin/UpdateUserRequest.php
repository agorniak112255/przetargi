<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Rules\ExistingWebRole;
use App\Services\Auth\NetworkAccessPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.users.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['sometimes', 'string', new ExistingWebRole],
            'ui_template' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z0-9-]{1,40}$/'],
            'ui_mode' => ['sometimes', 'nullable', Rule::in(User::UI_MODES)],
            // operator ERP XL (Ope_Ident) — „moi klienci” w kampaniach; pusty = brak
            'erp_operator_ident' => ['sometimes', 'nullable', 'string', 'max:20'],
            // pracownik ERP XL (opiekun klientów, KtO_PrcNumer) — cele handlowców; null = brak; jeden pracownik = jedno konto
            'erp_employee_gid' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295', Rule::unique('users', 'erp_employee_gid')->ignore($user->id)],
            // dostęp z sieci: null = jak w grupie, any = z każdej sieci, local = tylko z sieci lokalnej
            'network_access' => ['sometimes', 'nullable', Rule::in(NetworkAccessPolicy::MODES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'erp_employee_gid.unique' => 'Ten pracownik ERP XL jest już przypisany do innego konta. Najpierw usuń tamto przypisanie.',
            'erp_employee_gid.integer' => 'Wybierz pracownika ERP XL z listy.',
            'erp_employee_gid.min' => 'Wybierz pracownika ERP XL z listy.',
            'erp_employee_gid.max' => 'Wybierz pracownika ERP XL z listy.',
        ];
    }
}
