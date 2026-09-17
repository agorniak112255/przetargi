<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendUserCredentialsRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Mail\AccountCredentialsMail;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
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
            ->map(static fn (User $user): array => $user->toAuthArray())
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

        return response()->json($user->toAuthArray(), 201);
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
        $user->save();

        if (isset($data['role'])) {
            $user->syncPrimaryRole($data['role']);
        }
        $this->applyAppearance($user, $data);

        return response()->json($user->fresh()->toAuthArray());
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()?->id === $user->id) {
            return response()->json(['message' => 'Nie możesz usunąć własnego konta.'], 422);
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
