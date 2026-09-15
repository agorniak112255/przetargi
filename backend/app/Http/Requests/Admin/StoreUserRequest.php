<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Rules\ExistingWebRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', new ExistingWebRole],
            // Wygląd ustawiony przez administratora; ten sam format co PATCH /me/preferences.
            'ui_template' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z0-9-]{1,40}$/'],
            'ui_mode' => ['sometimes', 'nullable', Rule::in(User::UI_MODES)],
        ];
    }
}
