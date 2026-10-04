<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\UserTeam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dodanie i zmiana zespołu (Administracja → Role). Lista członków zastępuje dotychczasową w całości;
 * is_leader = kierownik zespołu (widzi w raporcie „Wynik kampanii” kampanie członków).
 */
class SaveUserTeamRequest extends FormRequest
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
        $team = $this->route('team');
        $unique = Rule::unique('user_teams', 'name');
        if ($team instanceof UserTeam) {
            $unique = $unique->ignore($team->getKey());
        }

        return [
            'name' => ['required', 'string', 'max:100', $unique],
            'members' => ['present', 'array', 'max:500'],
            'members.*' => ['array'],
            'members.*.user_id' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'members.*.is_leader' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Podaj nazwę zespołu.',
            'name.max' => 'Nazwa zespołu: najwyżej 100 znaków.',
            'name.unique' => 'Zespół o takiej nazwie już istnieje.',
            'members.present' => 'Podaj listę członków zespołu (może być pusta).',
            'members.array' => 'Lista członków zespołu ma zły format.',
            'members.max' => 'Za dużo osób w zespole.',
            'members.*.array' => 'Członek zespołu ma zły format.',
            'members.*.user_id.required' => 'Każdy członek zespołu musi mieć wybraną osobę.',
            'members.*.user_id.integer' => 'Członek zespołu ma zły format.',
            'members.*.user_id.distinct' => 'Ta sama osoba jest na liście członków więcej niż raz.',
            'members.*.user_id.exists' => 'Nie ma takiego użytkownika.',
            'members.*.is_leader.required' => 'Zaznacz, czy osoba jest kierownikiem zespołu.',
            'members.*.is_leader.boolean' => 'Pole „kierownik zespołu” ma zły format.',
        ];
    }
}
