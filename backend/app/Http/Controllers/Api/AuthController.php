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

        $token = $user->createToken('spa')->plainTextToken;

        $this->activityLogger->log(
            action: 'login',
            user: $user,
            meta: ['label' => 'Logowanie'],
            request: $request,
        );

        return response()->json([
            'token' => $token,
            'user' => $user->toAuthArray(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($user->toAuthArray());
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
}
