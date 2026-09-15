<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            $this->activityLogger->log(
                action: 'login_failed',
                user: $user,
                meta: [
                    'label' => 'Nieudane logowanie',
                    'email' => $credentials['email'],
                ],
                request: $request,
            );

            throw ValidationException::withMessages([
                'email' => ['Nieprawidłowy e-mail lub hasło.'],
            ]);
        }

        $newToken = $user->createToken('spa');
        $newToken->accessToken->forceFill([
            'ip_address' => $request->ip(),
            'user_agent' => $this->truncateUserAgent($request->userAgent()),
            'presence_path' => null,
            'presence_at' => now(),
        ])->save();

        $user->forceFill([
            'last_login_at' => now(),
            'last_seen_at' => now(),
        ])->save();

        $this->activityLogger->log(
            action: 'login',
            user: $user,
            meta: ['label' => 'Logowanie'],
            request: $request,
        );

        return response()->json([
            'token' => $newToken->plainTextToken,
            'user' => $user->toAuthArray(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($user->toAuthArray());
    }

    /**
     * Zapisuje preferencje wyglądu na koncie. Oba klucze są wymagane, a zapis nadpisuje cały obiekt.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template' => ['present', 'nullable', 'string', 'regex:/^[a-z0-9-]{1,40}$/'],
            'mode' => ['present', 'nullable', 'in:'.implode(',', User::UI_MODES)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill([
            'ui_preferences' => [
                'template' => $validated['template'] ?? null,
                'mode' => $validated['mode'] ?? null,
            ],
        ])->save();

        return response()->json($user->toAuthArray());
    }

    /**
     * Sygnał obecności z SPA: zapisuje bieżącą podstronę na tokenie tej sesji i czas ostatniej aktywności konta.
     */
    public function presence(Request $request): Response
    {
        $validated = $request->validate([
            // Sama ścieżka: bez ?query i #hash, bo tam bywają wpisane wyszukiwane frazy.
            'path' => ['required', 'string', 'max:255', 'regex:#^/[^\s?\#]*$#'],
        ]);

        /** @var User $user */
        $user = $request->user();

        // Przy Sanctum::actingAs w testach bieżący „token” nie jest rekordem z bazy — wtedy zapisujemy tylko konto.
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->forceFill([
                'presence_path' => $validated['path'],
                'presence_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $this->truncateUserAgent($request->userAgent()),
            ])->save();
        }

        $user->forceFill(['last_seen_at' => now()])->save();

        return response()->noContent();
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user !== null) {
            $this->activityLogger->log(
                action: 'logout',
                user: $user,
                meta: ['label' => 'Wylogowanie'],
                request: $request,
            );
        }

        $user?->currentAccessToken()?->delete();

        return response()->json(['message' => 'OK']);
    }

    private function truncateUserAgent(?string $userAgent): ?string
    {
        return $userAgent === null ? null : mb_substr($userAgent, 0, 512);
    }
}
