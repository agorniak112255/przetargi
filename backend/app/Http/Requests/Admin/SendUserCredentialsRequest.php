<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SendUserCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.users.manage') ?? false;
    }

    /**
     * Hasło opcjonalne: brak = system wygeneruje nowe i wyśle je w wiadomości.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
        ];
    }
}
