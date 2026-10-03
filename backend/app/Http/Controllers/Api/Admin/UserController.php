<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendUserCredentialsRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Mail\AccountCredentialsMail;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => $this->present($user))
            ->values();

        return response()->json($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
        ]);
        $user->syncPrimaryRole($data['role']);
        $this->applyAppearance($user, $data);

        return response()->json($this->present($user), 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['name'])) {
            $user->name = $data['name'];
        }
        if (isset($data['email'])) {
            $user->email = $data['email'];
        }
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        if (array_key_exists('erp_operator_ident', $data)) {
            $ident = mb_strtoupper(trim((string) $data['erp_operator_ident']));
            $user->forceFill(['erp_operator_ident' => $ident === '' ? null : $ident]);
        }
        if (array_key_exists('erp_employee_gid', $data)) {
            $user->forceFill(['erp_employee_gid' => $data['erp_employee_gid'] === null ? null : (int) $data['erp_employee_gid']]);
        }
        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            // dwa równoczesne zapisy tego samego pracownika — walidacja przeszła w obu, baza przepuściła jeden
            $message = 'Ten pracownik ERP XL jest już przypisany do innego konta.';

            return response()->json(['message' => $message, 'errors' => ['erp_employee_gid' => [$message]]], 422);
        }

        if (isset($data['role'])) {
            $user->syncPrimaryRole($data['role']);
        }
        $this->applyAppearance($user, $data);

        return response()->json($this->present($user->fresh()));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()?->id === $user->id) {
            return response()->json(['message' => 'Nie możesz usunąć własnego konta.'], 422);
        }
        // kampanie zostają w historii (wynik, wypisy) — klucz obcy campaigns.user_id blokuje usunięcie autora
        if (Campaign::query()->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'Użytkownik ma kampanie — nie można go usunąć.'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'OK']);
    }

    /**
     * Wysyła użytkownikowi dane dostępu. Hasło w bazie jest hashowane, więc nie da się
     * przypomnieć dotychczasowego — wiadomość niesie hasło podane przez administratora
     * albo wygenerowane tutaj. Hash zapisujemy dopiero po udanej wysyłce, żeby nieudany
     * e-mail nie zostawił konta z hasłem, którego nikt nie zna.
     */
    public function sendCredentials(SendUserCredentialsRequest $request, User $user): JsonResponse
    {
        $password = $request->validated()['password'] ?? null;
        $generated = $password === null || $password === '';
        $plainPassword = $generated ? Str::password(12, symbols: false) : (string) $password;

        $appUrl = rtrim((string) config('app.frontend_url'), '/');

        try {
            Mail::to($user->email)->send(
                new AccountCredentialsMail($user, $plainPassword, $appUrl, $this->roleLabel($user))
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => 'Nie udało się wysłać wiadomości: '.$e->getMessage(),
            ], 422);
        }

        $user->password = Hash::make($plainPassword);
        $user->save();

        return response()->json([
            'ok' => true,
            'password_generated' => $generated,
            'message' => 'Dane dostępu wysłane na '.$user->email.'.',
        ]);
    }

    /**
     * Dane użytkownika w panelu admina: jak /me plus operator i pracownik ERP XL (tylko tutaj — ustawia je administrator).
     *
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            ...$user->toAuthArray(),
            'erp_operator_ident' => $user->getAttribute('erp_operator_ident'),
            'erp_employee_gid' => $user->erp_employee_gid === null ? null : (int) $user->erp_employee_gid,
        ];
    }

    private function roleLabel(User $user): string
    {
        $roleName = (string) $user->toAuthArray()['role'];
        $role = Role::query()->where('guard_name', 'web')->where('name', $roleName)->first();
        $display = $role?->display_name;

        if (is_string($display) && $display !== '') {
            return $display;
        }

        return PermissionCatalog::roleLabels()[$roleName] ?? $roleName;
    }

    /**
     * Wygląd ustawiony przez administratora — zapis tylko, gdy żądanie niesie którykolwiek klucz.
     * Nadpisuje cały obiekt jak PATCH /me/preferences; null w obu = użytkownik wybierze sam.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyAppearance(User $user, array $data): void
    {
        if (! array_key_exists('ui_template', $data) && ! array_key_exists('ui_mode', $data)) {
            return;
        }

        $user->forceFill([
            'ui_preferences' => [
                'template' => $data['ui_template'] ?? null,
                'mode' => $data['ui_mode'] ?? null,
            ],
        ])->save();
    }
}
