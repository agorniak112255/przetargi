<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveUserTeamRequest;
use App\Models\User;
use App\Models\UserTeam;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Zespoły (Administracja → Role, admin.roles.manage). KONTRAKT zamrożony 04.10.2026.
 * GET    /api/admin/teams        → {data: Team[], users: [{id, name, role}]}
 * POST   /api/admin/teams        {name, members: [{user_id, is_leader}]} → {data: Team} 201
 * PUT    /api/admin/teams/{team} {name, members: [...]} → {data: Team} (lista członków zastępowana w całości)
 * DELETE /api/admin/teams/{team} → {message}
 * Team = {id, name, members: [{user_id, name, is_leader}]}
 * Wpis w dzienniku aktywności robi middleware LogApiActivity (jak przy rolach).
 */
class TeamController extends Controller
{
    public function index(): JsonResponse
    {
        $teams = UserTeam::query()
            ->with('members')
            ->orderBy('name')
            ->get()
            ->map(fn (UserTeam $team): array => $this->present($team))
            ->values();

        // wszyscy użytkownicy do wyboru członków zespołu
        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'role'])
            ->map(static fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'role' => (string) $user->role,
            ])
            ->values();

        return response()->json(['data' => $teams, 'users' => $users]);
    }

    public function store(SaveUserTeamRequest $request): JsonResponse
    {
        /** @var array{name: string, members: list<array{user_id: int|string, is_leader: bool|int|string}>} $data */
        $data = $request->validated();

        try {
            $team = DB::transaction(function () use ($data): UserTeam {
                $team = UserTeam::query()->create(['name' => $data['name']]);
                $team->members()->sync($this->membersForSync($data['members']));

                return $team;
            });
        } catch (UniqueConstraintViolationException) {
            // dwa równoległe zapisy tej samej nazwy — walidacja przepuściła oba
            return $this->duplicateName();
        }

        return response()->json(['data' => $this->present($team->load('members'))], 201);
    }

    public function update(SaveUserTeamRequest $request, UserTeam $team): JsonResponse
    {
        /** @var array{name: string, members: list<array{user_id: int|string, is_leader: bool|int|string}>} $data */
        $data = $request->validated();

        try {
            DB::transaction(function () use ($team, $data): void {
                $team->name = $data['name'];
                $team->save();
                $team->members()->sync($this->membersForSync($data['members']));
            });
        } catch (UniqueConstraintViolationException) {
            return $this->duplicateName();
        }

        return response()->json(['data' => $this->present($team->load('members'))]);
    }

    public function destroy(UserTeam $team): JsonResponse
    {
        DB::transaction(function () use ($team): void {
            $team->members()->detach();
            $team->delete();
        });

        return response()->json(['message' => 'Usunięto zespół.']);
    }

    /**
     * @param  list<array{user_id: int|string, is_leader: bool|int|string}>  $members
     * @return array<int, array{is_leader: bool}>
     */
    private function membersForSync(array $members): array
    {
        $sync = [];
        foreach ($members as $member) {
            $sync[(int) $member['user_id']] = ['is_leader' => filter_var($member['is_leader'], FILTER_VALIDATE_BOOLEAN)];
        }

        return $sync;
    }

    /**
     * Kierownicy na początku, potem alfabetycznie.
     *
     * @return array{id: int, name: string, members: list<array{user_id: int, name: string, is_leader: bool}>}
     */
    private function present(UserTeam $team): array
    {
        $members = $team->members
            ->map(static fn (User $user): array => [
                'user_id' => (int) $user->id,
                'name' => (string) $user->name,
                'is_leader' => (bool) $user->getRelationValue('pivot')?->getAttribute('is_leader'),
            ])
            ->sort(static fn (array $a, array $b): int => [! $a['is_leader'], mb_strtolower($a['name'])] <=> [! $b['is_leader'], mb_strtolower($b['name'])])
            ->values()
            ->all();

        return [
            'id' => (int) $team->id,
            'name' => (string) $team->name,
            'members' => $members,
        ];
    }

    private function duplicateName(): JsonResponse
    {
        return response()->json([
            'message' => 'Zespół o takiej nazwie już istnieje.',
            'errors' => ['name' => ['Zespół o takiej nazwie już istnieje.']],
        ], 422);
    }
}
